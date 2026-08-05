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
        float|int|string $qty,
        float|int|string $costPrice,
        ?string $batchNumber = null,
        ?int $createdBy = null,
    ): StockBatch {
        if ($this->bcComp($qty, '0') <= 0) {
            throw new InvalidArgumentException('Ingress qty must be positive');
        }
        if ($this->bcComp($costPrice, '0') < 0) {
            throw new InvalidArgumentException('Cost price cannot be negative');
        }

        $qty = $this->bcRound($qty, 3);
        $costPrice = $this->bcRound($costPrice, 2);

        return DB::transaction(function () use (
            $tenantId, $warehouseId, $productId, $qty, $costPrice, $batchNumber, $createdBy
        ): StockBatch {
            $batch = StockBatch::query()->withoutGlobalScopes()->forceCreate([
                'tenant_id' => $tenantId,
                'warehouse_id' => $warehouseId,
                'product_id' => $productId,
                'batch_number' => $batchNumber,
                'qty' => $qty,
                'remaining_qty' => $qty,
                'cost_price' => $costPrice,
                'received_at' => now(),
            ]);

            $stock = $this->ensureStock($tenantId, $warehouseId, $productId);
            $stock->actual = $this->bcAdd($stock->actual, $qty);
            $stock->available = $this->bcSub($stock->actual, $stock->reserved);
            $stock->quantity = $this->bcAdd($stock->quantity, $qty);
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
        float|int|string $qty,
        ?int $createdBy = null,
        ?int $orderId = null,
        ?int $orderItemId = null,
        bool $allowOverdraft = false,
    ): array {
        if ($this->bcComp($qty, '0') <= 0) {
            throw new InvalidArgumentException('Write-off qty must be positive');
        }

        return DB::transaction(function () use ($tenantId, $warehouseId, $productId, $qty, $createdBy, $orderId, $orderItemId, $allowOverdraft): array {
            $stock = $this->lockStock($tenantId, $warehouseId, $productId);

            if (! $allowOverdraft && $this->bcComp($this->bcAdd($stock->available, '0.0001'), $qty) < 0) {
                throw new InsufficientStockException('available_less_than_qty');
            }

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

            $remaining = $this->bcRound($qty, 3);
            $writtenOff = '0';
            $cost = '0';
            $batchTrace = [];
            $hasOverdraft = false;
            $shortfall = '0';

            foreach ($batches as $batch) {
                if ($this->bcComp($remaining, '0') <= 0) {
                    break;
                }

                $availableInBatch = $this->bcRound($batch->remaining_qty, 3);
                $take = $this->bcMin($availableInBatch, $remaining);

                $batch->remaining_qty = $this->bcSub($availableInBatch, $take);
                $batch->save();

                $unitCost = $this->bcRound($batch->cost_price, 2);
                $totalCost = $this->bcRound($this->bcMul($take, $unitCost), 2);

                $writtenOff = $this->bcAdd($writtenOff, $take);
                $cost = $this->bcAdd($cost, $totalCost);
                $batchTrace[(int) $batch->id] = $this->bcRound($take, 3);

                \Autometria\Models\StockLotDeduction::query()->withoutGlobalScopes()->forceCreate([
                    'tenant_id' => $tenantId,
                    'order_id' => $orderId,
                    'order_item_id' => $orderItemId,
                    'stock_batch_id' => $batch->id,
                    'warehouse_id' => $warehouseId,
                    'product_id' => $productId,
                    'quantity' => $this->bcRound($take, 3),
                    'unit_cost' => $unitCost,
                    'total_cost' => $totalCost,
                ]);

                $remaining = $this->bcSub($remaining, $take);
            }

            if (! $this->bcAlmostZero($remaining)) {
                if (! $allowOverdraft) {
                    throw new InsufficientStockException('batch_coverage_gap');
                }

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

                $shortfall = $this->bcRound($remaining, 3);
                $hasOverdraft = true;

                $overdraftBatch = StockBatch::query()->withoutGlobalScopes()->forceCreate([
                    'tenant_id' => $tenantId,
                    'warehouse_id' => $warehouseId,
                    'product_id' => $productId,
                    'batch_number' => 'OVERDRAFT-'.\Illuminate\Support\Str::uuid()->toString(),
                    'qty' => $this->bcSub('0', $shortfall),
                    'remaining_qty' => $this->bcSub('0', $shortfall),
                    'cost_price' => $this->bcRound($lastCost, 2),
                    'is_overdraft' => true,
                    'received_at' => now(),
                ]);

                $unitCost = $this->bcRound($lastCost, 2);
                $totalCost = $this->bcRound($this->bcMul($shortfall, $unitCost), 2);
                $writtenOff = $this->bcAdd($writtenOff, $shortfall);
                $cost = $this->bcAdd($cost, $totalCost);
                $batchTrace[(int) $overdraftBatch->id] = $this->bcSub('0', $shortfall);

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

                $remaining = '0';
            }

            $stock->actual = $this->bcSub($stock->actual, $writtenOff);
            $stock->available = $this->bcSub($stock->actual, $stock->reserved);
            $stock->quantity = $this->bcSub($stock->quantity, $writtenOff);
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
                'written_off' => $this->bcToFloat($this->bcRound($writtenOff, 3)),
                'cost' => $this->bcToFloat($this->bcRound($cost, 2)),
                'batches' => $batchTrace,
                'overdraft' => $hasOverdraft,
                'has_overdraft' => $hasOverdraft,
                'shortfall' => $this->bcToFloat($this->bcRound($shortfall, 3)),
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
        float|int|string $qty,
        ?int $createdBy = null,
    ): array {
        if ($this->bcComp($qty, '0') <= 0) {
            throw new InvalidArgumentException('Reverse qty must be positive');
        }

        return DB::transaction(function () use ($tenantId, $orderId, $orderItemId, $qty, $createdBy): array {
            $deductions = \Autometria\Models\StockLotDeduction::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('order_id', $orderId)
                ->where('order_item_id', $orderItemId)
                ->orderByDesc('id')
                ->lockForUpdate()
                ->get();

            if ($deductions->isEmpty()) {
                throw new InsufficientStockException('no_lot_deductions_for_refund');
            }

            $remaining = $this->bcRound($qty, 3);
            $restored = '0';
            $cost = '0';
            $batchTrace = [];
            $warehouseId = (int) $deductions->first()->warehouse_id;
            $productId = (int) $deductions->first()->product_id;

            $stock = $this->lockStock($tenantId, $warehouseId, $productId);

            foreach ($deductions as $deduction) {
                if ($this->bcAlmostZero($remaining)) {
                    break;
                }

                $already = $deduction->refunded_qty ?? 0;
                $availableToRestore = $this->bcSub($deduction->quantity, $already);
                if ($this->bcAlmostZero($availableToRestore) || $this->bcComp($availableToRestore, '0') <= 0) {
                    continue;
                }

                $take = $this->bcMin($availableToRestore, $remaining);
                $batch = StockBatch::query()->withoutGlobalScopes()
                    ->whereKey($deduction->stock_batch_id)
                    ->lockForUpdate()
                    ->first();

                if ($batch === null) {
                    continue;
                }

                if ((bool) $batch->is_overdraft) {
                    $batch->remaining_qty = $this->bcAdd($batch->remaining_qty, $take);
                    $batch->qty = $this->bcAdd($batch->qty, $take);
                } else {
                    $batch->remaining_qty = $this->bcAdd($batch->remaining_qty, $take);
                }
                $batch->save();

                $deduction->refunded_qty = $this->bcAdd($already, $take);
                $deduction->save();

                $unitCost = $this->bcRound($deduction->unit_cost, 2);
                $restored = $this->bcAdd($restored, $take);
                $cost = $this->bcAdd($cost, $this->bcRound($this->bcMul($take, $unitCost), 2));
                $batchTrace[(int) $batch->id] = $this->bcAdd($batchTrace[(int) $batch->id] ?? 0, $take);
                $remaining = $this->bcSub($remaining, $take);
            }

            if (! $this->bcAlmostZero($remaining)) {
                throw new InsufficientStockException('refund_qty_exceeds_deductions');
            }

            $stock->actual = $this->bcAdd($stock->actual, $restored);
            $stock->available = $this->bcSub($stock->actual, $stock->reserved);
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
                'restored' => $this->bcToFloat($this->bcRound($restored, 3)),
                'cost' => $this->bcToFloat($this->bcRound($cost, 2)),
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
        float|int|string $newQty,
        ?string $reason = null,
        ?int $createdBy = null,
    ): StockBatch {
        if ($this->bcComp($newQty, '0') < 0) {
            throw new InvalidArgumentException('Adjusted qty cannot be negative');
        }

        $newQty = $this->bcRound($newQty, 3);

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
                $batch = StockBatch::query()->withoutGlobalScopes()->forceCreate([
                    'tenant_id' => $tenantId,
                    'warehouse_id' => $warehouseId,
                    'product_id' => $productId,
                    'batch_number' => 'ADJ-' . now()->format('YmdHis'),
                    'qty' => $newQty,
                    'remaining_qty' => $newQty,
                    'cost_price' => 0,
                    'received_at' => now(),
                ]);
            } else {
                $batch->remaining_qty = $newQty;
                $batch->qty = $newQty;
                $batch->save();
            }

            $stock->actual = $newQty;
            $stock->available = $this->bcSub($stock->actual, $stock->reserved);
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
        float|int|string $qty,
        ?int $createdBy = null,
        ?string $docRef = null,
    ): array {
        if ($fromWarehouseId === $toWarehouseId) {
            throw new InvalidArgumentException('Source and destination warehouses must differ');
        }
        if ($this->bcComp($qty, '0') <= 0) {
            throw new InvalidArgumentException('Transfer qty must be positive');
        }

        return DB::transaction(function () use (
            $tenantId, $fromWarehouseId, $toWarehouseId, $productId, $qty, $createdBy, $docRef
        ): array {
            $fromStock = $this->lockStock($tenantId, $fromWarehouseId, $productId);
            if ($this->bcComp($this->bcAdd($fromStock->available, '0.0001'), $qty) < 0) {
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

            $remaining = $this->bcRound($qty, 3);
            $moved = '0';
            $trace = [];
            $ref = $docRef ?: ('TR-'.now()->format('YmdHis'));

            foreach ($batches as $batch) {
                if ($this->bcAlmostZero($remaining)) {
                    break;
                }
                $available = $this->bcRound($batch->remaining_qty, 3);
                $take = $this->bcMin($available, $remaining);
                $batch->remaining_qty = $this->bcSub($available, $take);
                $batch->save();

                $costPrice = $this->bcRound($batch->cost_price, 2);
                $toBatch = StockBatch::query()->withoutGlobalScopes()->forceCreate([
                    'tenant_id' => $tenantId,
                    'warehouse_id' => $toWarehouseId,
                    'product_id' => $productId,
                    'batch_number' => $ref.'-'.$batch->id,
                    'qty' => $this->bcRound($take, 3),
                    'remaining_qty' => $this->bcRound($take, 3),
                    'cost_price' => $costPrice,
                    'received_at' => $batch->received_at ?? now(),
                    'is_overdraft' => false,
                ]);

                $trace[] = [
                    'from_batch_id' => (int) $batch->id,
                    'to_batch_id' => (int) $toBatch->id,
                    'qty' => $this->bcRound($take, 3),
                    'cost_price' => $costPrice,
                ];

                $moved = $this->bcAdd($moved, $take);
                $remaining = $this->bcSub($remaining, $take);
            }

            if (! $this->bcAlmostZero($remaining)) {
                throw new InsufficientStockException('batch_coverage_gap');
            }

            $fromStock->actual = $this->bcSub($fromStock->actual, $moved);
            $fromStock->available = $this->bcSub($fromStock->actual, $fromStock->reserved);
            $fromStock->save();

            $toStock = $this->lockStock($tenantId, $toWarehouseId, $productId);
            $toStock->actual = $this->bcAdd($toStock->actual, $moved);
            $toStock->available = $this->bcSub($toStock->actual, $toStock->reserved);
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

            return ['moved' => $this->bcToFloat($this->bcRound($moved, 3)), 'batches' => $trace];
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

        $gap = $this->bcSub($stock->actual, $batchSum);
        if ($this->bcAlmostZero($gap) || $this->bcComp($gap, '0') <= 0) {
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
