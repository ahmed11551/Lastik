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
use Autometria\Support\AuditLog;
use InvalidArgumentException;
use Illuminate\Support\Facades\DB;

/**
 * StockBatchService — партионный учёт (FIFO).
 *
 * - ingress():  приходная партия (пополнение склада).
 * - writeOff(): списание СТРОГО по FIFO (старые партии first), под lockForUpdate.
 * - adjust():   инвентаризация (коррекция остатка партии + сверка со Stock.actual).
 */
final class StockBatchService
{
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
            $stock->actual = (float) $stock->actual + $qty;
            $stock->available = (float) $stock->actual - (float) $stock->reserved;
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

            if ((float) $stock->available + 0.0001 < $qty) {
                throw new InsufficientStockException('available_less_than_qty');
            }

            // FIFO: самые старые партии первыми. Блокируем их.
            $batches = StockBatch::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('warehouse_id', $warehouseId)
                ->where('product_id', $productId)
                ->where('remaining_qty', '>', 0)
                ->orderBy('received_at', 'asc')
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get();

            $remaining = round($qty, 3);
            $writtenOff = 0.0;
            $cost = 0.0;
            $batchTrace = [];

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

                $writtenOff += $take;
                $cost += $totalCost;
                $batchTrace[(int) $batch->id] = round($take, 3);

                // Фиксируем детализацию списания партии (FIFO COGS) с unit_cost
                // строго на момент продажи.
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
                    // Данные партий не покрывают доступный остаток — откатываем.
                    throw new InsufficientStockException('batch_coverage_gap');
                }

                // Overdraft-фоллбэк (только для офлайн-чеков с фискальным приоритетом):
                // создаём техническую отрицательную партию под недостающий объём.
                $lastCost = (float) ($batches->last()?->cost_price ?? 0);
                $shortfall = round($remaining, 3);
                $overdraftBatch = \Autometria\Models\StockBatch::query()->withoutGlobalScopes()->forceCreate([
                    'tenant_id' => $tenantId,
                    'warehouse_id' => $warehouseId,
                    'product_id' => $productId,
                    'batch_number' => 'OVERDRAFT-' . \Illuminate\Support\Str::uuid()->toString(),
                    'qty' => -$shortfall,
                    'remaining_qty' => -$shortfall,
                    'cost_price' => round($lastCost, 2),
                    'is_overdraft' => true,
                    'received_at' => now(),
                ]);

                $unitCost = round($lastCost, 2);
                $totalCost = round($shortfall * $unitCost, 2);
                $writtenOff += $shortfall;
                $cost += $totalCost;

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

                $remaining = 0.0;
            }

            // Уменьшаем суммарный остаток склада (может уйти в минус при overdraft).
            $stock->actual = (float) $stock->actual - $writtenOff;
            $stock->available = (float) $stock->actual - (float) $stock->reserved;
            $stock->save();

            $isOverdraft = $remaining < 0 || ($batches->count() === 0 && $allowOverdraft);

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
                ],
            );

            return [
                'written_off' => round($writtenOff, 3),
                'cost' => round($cost, 2),
                'batches' => $batchTrace,
                'overdraft' => $isOverdraft,
                'shortfall' => $isOverdraft ? round($shortfall ?? 0, 3) : 0.0,
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
