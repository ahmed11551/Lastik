<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @package    Autometria\Services
 * @copyright  (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author     Себиев Ахмед Сулейманович (Chief Software Architect / Lead Developer)
 * @license    Proprietary & Confidential.
 */

declare(strict_types=1);

namespace Autometria\Services;

use Autometria\Exceptions\Domain\InsufficientStockException;
use Autometria\Models\Stock;
use Autometria\Models\StockBatch;
use Autometria\Services\Traits\BcMathDecimal;
use Autometria\Support\AuditLog;
use InvalidArgumentException;
use Illuminate\Support\Facades\DB;

/**
 * StockBatchService — партионный учёт (FIFO).
 *
 * - ingress():  приходная партия (пополнение склада).
 * - writeOff(): списание СТРОГО по FIFO (старые партии first), под lockForUpdate.
 * - reverseWriteOff(): возврат на исходные партии по StockLotDeduction.
 * - adjust():   инвентаризация (коррекция остатка партии + сверка со Stock.actual).
 */
final class StockBatchService
{
    use BcMathDecimal;
    /**
     * Приход товара партией. Возвращает созданную партию.
     */
    public function ingress(
        int $tenantId,
        int $warehouseId,
        int $productId,
        float $qty,
        float $costPrice,
        ?string $batchNumber = null,
        ?int $createdBy = null,
    ): StockBatch {
        if ($qty <= 0) {
            throw new InvalidArgumentException('Ingress qty must be positive');
        }
        if ($costPrice < 0) {
            throw new InvalidArgumentException('Cost price cannot be negative');
        }

        return DB::transaction(function () use (
            $tenantId, $warehouseId, $productId, $qty, $costPrice, $batchNumber, $createdBy
        ): StockBatch {
            $batch = StockBatch::query()->withoutGlobalScopes()->forceCreate([
                'tenant_id' => $tenantId,
                'warehouse_id' => $warehouseId,
                'product_id' => $productId,
                'batch_number' => $batchNumber,
                'qty' => round($qty, 3),
                'remaining_qty' => round($qty, 3),
                'cost_price' => round($costPrice, 2),
                'received_at' => now(),
            ]);

            $stock = $this->ensureStock($tenantId, $warehouseId, $productId);
            $stock->actual = (float) $this->bcAdd($stock->actual, $qty);
            $stock->available = (float) $this->bcSub($stock->actual, $stock->reserved);
            $stock->quantity = (float) $this->bcAdd((float) $stock->quantity, $qty);
            $stock->save();

            AuditLog::write(
                $tenantId,
                $createdBy ?? auth()->id(),
                'stock.batch.ingress',
                StockBatch::class,
                (int) $batch->id,
                [],
                ['qty' => $qty, 'cost_price' => $costPrice, 'warehouse_id' => $warehouseId],
            );

            return $batch;
        });
    }

    /**
     * FIFO-списание: выбирает партии ORDER BY received_at ASC и списывает
     * начиная с самой старой. Возвращает суммарную списанную стоимость (cost).
     *
     * @return array{written_off: float, cost: float, batches: array<int, float>}
     */
    public function writeOff(
        int $tenantId,
        int $warehouseId,
        int $productId,
        float $qty,
        ?int $createdBy = null,
        ?int $orderId = null,
        ?int $orderItemId = null,
        bool $allowOverdraft = false,
    ): array {
        if ($qty <= 0) {
            throw new InvalidArgumentException('Write-off qty must be positive');
        }

        return DB::transaction(function () use ($tenantId, $warehouseId, $productId, $qty, $createdBy, $orderId, $orderItemId, $allowOverdraft): array {
            // Блокируем остаток склада (пессимистичная блокировка).
            $stock = $this->lockStock($tenantId, $warehouseId, $productId);

            if (! $allowOverdraft && (float) $stock->available + 0.0001 < $qty) {
                throw new InsufficientStockException('available_less_than_qty');
            }

            // FIFO: самые старые партии первыми. Блокируем их.
            // Овердрафт-партии (отрицательные) не участвуют в покрытии продаж.
            $batches = StockBatch::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('warehouse_id', $warehouseId)
                ->where('product_id', $productId)
                ->where('remaining_qty', '>', 0)
                ->where(function ($q): void {
                    $q->where('is_overdraft', false)->orWhereNull('is_overdraft');
                })
                ->orderBy('received_at', 'asc')
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get();

            $remaining = round($qty, 3);
            $writtenOff = 0.0;
            $cost = 0.0;
            $batchTrace = [];
            $hasOverdraft = false;
            $shortfall = 0.0;

            foreach ($batches as $batch) {
                if ($remaining <= 0) {
                    break;
                }

                $availableInBatch = (float) $batch->remaining_qty;
                $take = min($availableInBatch, $remaining);

                $batch->remaining_qty = round($availableInBatch - $take, 3);
                $batch->save();

                $unitCost = round((float) $batch->cost_price, 2);
                $totalCost = round($take * $unitCost, 2);

                $writtenOff = $this->bcAdd($writtenOff, $take);
                $cost = $this->bcAdd($cost, $totalCost);
                $batchTrace[(int) $batch->id] = round($take, 3);

                \Autometria\Models\StockLotDeduction::query()->withoutGlobalScopes()->forceCreate([
                    'tenant_id' => $tenantId,
                    'order_id' => $orderId,
                    'order_item_id' => $orderItemId,
                    'stock_batch_id' => $batch->id,
                    'warehouse_id' => $warehouseId,
                    'product_id' => $productId,
                    'quantity' => round($take, 3),
                    'unit_cost' => $unitCost,
                    'total_cost' => $totalCost,
                ]);

                $remaining = round($remaining - $take, 3);
            }

            if ($remaining > 0.0001) {
                if (! $allowOverdraft) {
                    throw new InsufficientStockException('batch_coverage_gap');
                }

                // Overdraft fallback (offline / fiscal priority).
                $lastCost = (float) (StockBatch::query()->withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('warehouse_id', $warehouseId)
                    ->where('product_id', $productId)
                    ->where(function ($q): void {
                        $q->where('is_overdraft', false)->orWhereNull('is_overdraft');
                    })
                    ->orderByDesc('received_at')
                    ->orderByDesc('id')
                    ->value('cost_price') ?? ($batches->last()?->cost_price ?? 0));

                $shortfall = round($remaining, 3);
                $hasOverdraft = true;

                $overdraftBatch = StockBatch::query()->withoutGlobalScopes()->forceCreate([
                    'tenant_id' => $tenantId,
                    'warehouse_id' => $warehouseId,
                    'product_id' => $productId,
                    'batch_number' => 'OVERDRAFT-'.\Illuminate\Support\Str::uuid()->toString(),
                    'qty' => -$shortfall,
                    'remaining_qty' => -$shortfall,
                    'cost_price' => round($lastCost, 2),
                    'is_overdraft' => true,
                    'received_at' => now(),
                ]);

                $unitCost = round($lastCost, 2);
                $totalCost = round($shortfall * $unitCost, 2);
                $writtenOff = $this->bcAdd($writtenOff, $shortfall);
                $cost = $this->bcAdd($cost, $totalCost);
                $batchTrace[(int) $overdraftBatch->id] = -$shortfall;

                \Autometria\Models\StockLotDeduction::query()->withoutGlobalScopes()->forceCreate([
                    'tenant_id' => $tenantId,
                    'order_id' => $orderId,
                    'order_item_id' => $orderItemId,
                    'stock_batch_id' => $overdraftBatch->id,
                    'warehouse_id' => $warehouseId,
                    'product_id' => $productId,
                    'quantity' => $shortfall,
                    'unit_cost' => $unitCost,
                    'total_cost' => $totalCost,
                ]);

                if ($orderId !== null) {
                    \Autometria\Models\InventoryAlert::query()->withoutGlobalScopes()->forceCreate([
                        'tenant_id' => $tenantId,
                        'product_id' => $productId,
                        'warehouse_id' => $warehouseId,
                        'type' => \Autometria\Models\InventoryAlert::TYPE_OVERDRAFT,
                        'message' => sprintf(
                            'Овердрафт остатка: product #%d warehouse #%d shortfall %s (order #%d)',
                            $productId,
                            $warehouseId,
                            $shortfall,
                            $orderId,
                        ),
                    ]);
                }

                $remaining = 0.0;
            }

            // Уменьшаем суммарный остаток склада (может уйти в минус при overdraft).
            $stock->actual = (float) $stock->actual - $writtenOff;
            $stock->available = (float) $stock->actual - (float) $stock->reserved;
            $stock->quantity = (float) $stock->quantity - $writtenOff;
            $stock->save();

            AuditLog::write(
                $tenantId,
                $createdBy ?? auth()->id(),
                'stock.batch.write_off',
                Stock::class,
                (int) $stock->id,
                [],
                [
                    'product_id' => $productId,
                    'warehouse_id' => $warehouseId,
                    'qty' => $writtenOff,
                    'cost' => $cost,
                    'batches' => $batchTrace,
                    'has_overdraft' => $hasOverdraft,
                    'shortfall' => $shortfall,
                ],
            );

            return [
                'written_off' => round($writtenOff, 3),
                'cost' => round($cost, 2),
                'batches' => $batchTrace,
                'overdraft' => $hasOverdraft,
                'has_overdraft' => $hasOverdraft,
                'shortfall' => round($shortfall, 3),
            ];
        });
    }

    /**
     * Обратное FIFO-списание: возвращает qty на исходные партии по журналу StockLotDeduction.
     *
     * @return array{restored: float, cost: float, batches: array<int, float>}
     */
    public function reverseWriteOff(
        int $tenantId,
        int $orderId,
        int $orderItemId,
        float $qty,
        ?int $createdBy = null,
    ): array {
        if ($qty <= 0) {
            throw new InvalidArgumentException('Reverse qty must be positive');
        }

        return DB::transaction(function () use ($tenantId, $orderId, $orderItemId, $qty, $createdBy): array {
            $deductions = \Autometria\Models\StockLotDeduction::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('order_id', $orderId)
                ->where('order_item_id', $orderItemId)
                ->orderByDesc('id') // LIFO restore: last deducted first
                ->lockForUpdate()
                ->get();

            if ($deductions->isEmpty()) {
                throw new InsufficientStockException('no_lot_deductions_for_refund');
            }

            $remaining = round($qty, 3);
            $restored = 0.0;
            $cost = 0.0;
            $batchTrace = [];
            $warehouseId = (int) $deductions->first()->warehouse_id;
            $productId = (int) $deductions->first()->product_id;

            $stock = $this->lockStock($tenantId, $warehouseId, $productId);

            foreach ($deductions as $deduction) {
                if ($remaining <= 0.0001) {
                    break;
                }

                $already = (float) ($deduction->refunded_qty ?? 0);
                $availableToRestore = round((float) $deduction->quantity - $already, 3);
                if ($availableToRestore <= 0.0001) {
                    continue;
                }

                $take = min($availableToRestore, $remaining);
                $batch = StockBatch::query()->withoutGlobalScopes()
                    ->whereKey($deduction->stock_batch_id)
                    ->lockForUpdate()
                    ->first();

                if ($batch === null) {
                    continue;
                }

                if ((bool) $batch->is_overdraft) {
                    // Overdraft batch: move remaining_qty toward zero (less negative).
                    $batch->remaining_qty = round((float) $batch->remaining_qty + $take, 3);
                    $batch->qty = round((float) $batch->qty + $take, 3);
                } else {
                    $batch->remaining_qty = round((float) $batch->remaining_qty + $take, 3);
                }
                $batch->save();

                $deduction->refunded_qty = round($already + $take, 3);
                $deduction->save();

                $unitCost = round((float) $deduction->unit_cost, 2);
                $restored += $take;
                $cost = $this->bcAdd($cost, round($take * $unitCost, 2));
                $batchTrace[(int) $batch->id] = round(($batchTrace[(int) $batch->id] ?? 0) + $take, 3);
                $remaining = round($remaining - $take, 3);
            }

            if ($remaining > 0.0001) {
                throw new InsufficientStockException('refund_qty_exceeds_deductions');
            }

            $stock->actual = (float) $stock->actual + $restored;
            $stock->available = (float) $stock->actual - (float) $stock->reserved;
            $stock->save();

            AuditLog::write(
                $tenantId,
                $createdBy ?? auth()->id(),
                'stock.batch.reverse_write_off',
                Stock::class,
                (int) $stock->id,
                [],
                [
                    'order_id' => $orderId,
                    'order_item_id' => $orderItemId,
                    'qty' => $restored,
                    'cost' => $cost,
                    'batches' => $batchTrace,
                ],
            );

            return [
                'restored' => round($restored, 3),
                'cost' => round($cost, 2),
                'batches' => $batchTrace,
            ];
        });
    }

    /**
     * Инвентаризация: устанавливает фактический остаток партии (adjustment).
     * Корректирует Stock.actual под новую сумму по партиям.
     */
    public function adjust(
        int $tenantId,
        int $warehouseId,
        int $productId,
        float $newQty,
        ?string $reason = null,
        ?int $createdBy = null,
    ): StockBatch {
        if ($newQty < 0) {
            throw new InvalidArgumentException('Adjusted qty cannot be negative');
        }

        return DB::transaction(function () use ($tenantId, $warehouseId, $productId, $newQty, $reason, $createdBy): StockBatch {
            $stock = $this->lockStock($tenantId, $warehouseId, $productId);

            $batch = StockBatch::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('warehouse_id', $warehouseId)
                ->where('product_id', $productId)
                ->where('remaining_qty', '>', 0)
                ->orderBy('received_at', 'desc')
                ->orderBy('id', 'desc')
                ->lockForUpdate()
                ->first();

            if ($batch === null) {
                // Создаём корректировочную партию.adjustment (без закупочной цены = 0).
                $batch = StockBatch::query()->withoutGlobalScopes()->forceCreate([
                    'tenant_id' => $tenantId,
                    'warehouse_id' => $warehouseId,
                    'product_id' => $productId,
                    'batch_number' => 'ADJ-' . now()->format('YmdHis'),
                    'qty' => round($newQty, 3),
                    'remaining_qty' => round($newQty, 3),
                    'cost_price' => 0,
                    'received_at' => now(),
                ]);
            } else {
                $batch->remaining_qty = round($newQty, 3);
                $batch->qty = round($newQty, 3);
                $batch->save();
            }

            $stock->actual = round($newQty, 3);
            $stock->available = (float) $stock->actual - (float) $stock->reserved;
            $stock->save();

            AuditLog::write(
                $tenantId,
                $createdBy ?? auth()->id(),
                'stock.batch.adjust',
                StockBatch::class,
                (int) $batch->id,
                [],
                ['new_qty' => $newQty, 'reason' => $reason],
                [],
                $reason,
            );

            return $batch;
        });
    }

    /**
     * FIFO-перенос партий между складами с зеркальным обновлением Stock.
     *
     * @return array{moved: float, batches: list<array{from_batch_id: int, to_batch_id: int, qty: float, cost_price: float}>}
     */
    public function transferFifo(
        int $tenantId,
        int $fromWarehouseId,
        int $toWarehouseId,
        int $productId,
        float $qty,
        ?int $createdBy = null,
        ?string $docRef = null,
    ): array {
        if ($fromWarehouseId === $toWarehouseId) {
            throw new InvalidArgumentException('Source and destination warehouses must differ');
        }
        if ($qty <= 0) {
            throw new InvalidArgumentException('Transfer qty must be positive');
        }

        return DB::transaction(function () use (
            $tenantId, $fromWarehouseId, $toWarehouseId, $productId, $qty, $createdBy, $docRef
        ): array {
            $fromStock = $this->lockStock($tenantId, $fromWarehouseId, $productId);
            if ((float) $fromStock->available + 0.0001 < $qty) {
                throw new InsufficientStockException('available_less_than_qty');
            }

            $this->ensureBatchCoverage($tenantId, $fromWarehouseId, $productId, $fromStock);

            $batches = StockBatch::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('warehouse_id', $fromWarehouseId)
                ->where('product_id', $productId)
                ->where('remaining_qty', '>', 0)
                ->where(function ($q): void {
                    $q->where('is_overdraft', false)->orWhereNull('is_overdraft');
                })
                ->orderBy('received_at', 'asc')
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get();

            $remaining = round($qty, 3);
            $moved = 0.0;
            $trace = [];
            $ref = $docRef ?: ('TR-'.now()->format('YmdHis'));

            foreach ($batches as $batch) {
                if ($remaining <= 0.0001) {
                    break;
                }
                $available = (float) $batch->remaining_qty;
                $take = min($available, $remaining);
                $batch->remaining_qty = round($available - $take, 3);
                $batch->save();

                $toBatch = StockBatch::query()->withoutGlobalScopes()->forceCreate([
                    'tenant_id' => $tenantId,
                    'warehouse_id' => $toWarehouseId,
                    'product_id' => $productId,
                    'batch_number' => $ref.'-'.$batch->id,
                    'qty' => round($take, 3),
                    'remaining_qty' => round($take, 3),
                    'cost_price' => round((float) $batch->cost_price, 2),
                    'received_at' => $batch->received_at ?? now(),
                    'is_overdraft' => false,
                ]);

                $trace[] = [
                    'from_batch_id' => (int) $batch->id,
                    'to_batch_id' => (int) $toBatch->id,
                    'qty' => round($take, 3),
                    'cost_price' => round((float) $batch->cost_price, 2),
                ];

                $moved += $take;
                $remaining = round($remaining - $take, 3);
            }

            if ($remaining > 0.0001) {
                throw new InsufficientStockException('batch_coverage_gap');
            }

            $fromStock->actual = round((float) $fromStock->actual - $moved, 3);
            $fromStock->available = round((float) $fromStock->actual - (float) $fromStock->reserved, 3);
            $fromStock->save();

            $toStock = $this->lockStock($tenantId, $toWarehouseId, $productId);
            $toStock->actual = round((float) $toStock->actual + $moved, 3);
            $toStock->available = round((float) $toStock->actual - (float) $toStock->reserved, 3);
            $toStock->save();

            AuditLog::write(
                $tenantId,
                $createdBy ?? auth()->id(),
                'stock.batch.transferred',
                Stock::class,
                (int) $fromStock->id,
                [],
                [
                    'from_warehouse_id' => $fromWarehouseId,
                    'to_warehouse_id' => $toWarehouseId,
                    'product_id' => $productId,
                    'qty' => $moved,
                    'batches' => $trace,
                ],
            );

            return ['moved' => $moved, 'batches' => $trace];
        });
    }

    /**
     * Если Stock.actual опережает сумму партий — создаём балансирующую партию (legacy bridge).
     */
    public function ensureBatchCoverage(int $tenantId, int $warehouseId, int $productId, ?Stock $stock = null): void
    {
        $stock ??= $this->lockStock($tenantId, $warehouseId, $productId);

        $batchSum = (float) StockBatch::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->where(function ($q): void {
                $q->where('is_overdraft', false)->orWhereNull('is_overdraft');
            })
            ->sum('remaining_qty');

        $gap = round((float) $stock->actual - $batchSum, 3);
        if ($gap <= 0.0001) {
            return;
        }

        StockBatch::query()->withoutGlobalScopes()->forceCreate([
            'tenant_id' => $tenantId,
            'warehouse_id' => $warehouseId,
            'product_id' => $productId,
            'batch_number' => 'BAL-'.now()->format('YmdHis').'-'.$productId,
            'qty' => $gap,
            'remaining_qty' => $gap,
            'cost_price' => 0,
            'received_at' => now()->subYear(),
            'is_overdraft' => false,
        ]);
    }

    private function ensureStock(int $tenantId, int $warehouseId, int $productId): Stock
    {
        $stock = Stock::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->first();

        if ($stock === null) {
            $stock = Stock::query()->withoutGlobalScopes()->forceCreate([
                'tenant_id' => $tenantId,
                'warehouse_id' => $warehouseId,
                'product_id' => $productId,
                'actual' => 0,
                'reserved' => 0,
                'available' => 0,
            ]);
        }

        return $stock;
    }

    private function lockStock(int $tenantId, int $warehouseId, int $productId): Stock
    {
        $stock = Stock::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->lockForUpdate()
            ->first();

        if ($stock === null) {
            $stock = Stock::query()->withoutGlobalScopes()->forceCreate([
                'tenant_id' => $tenantId,
                'warehouse_id' => $warehouseId,
                'product_id' => $productId,
                'actual' => 0,
                'reserved' => 0,
                'available' => 0,
            ]);
            $stock = Stock::query()->withoutGlobalScopes()
                ->whereKey($stock->id)
                ->lockForUpdate()
                ->firstOrFail();
        }

        return $stock;
    }
}
