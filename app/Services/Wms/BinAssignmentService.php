<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Services\Wms;

use Autometria\Models\Warehouse;
use Autometria\Models\Wms\StockBatch;
use Autometria\Models\Wms\WarehouseBin;
use Autometria\Services\Traits\BcMathDecimal;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * WMS 2.0 — bin suggestion for receiving + FEFO/FIFO deduction (BCMath only).
 */
final class BinAssignmentService
{
    use BcMathDecimal;

    /**
     * Find an open bin: RECEIVING first, then STORAGE. Weight checked via BCMath.
     */
    public function suggestBinForReceiving(int $warehouseId, string $weightKg = '0.000'): ?WarehouseBin
    {
        $tenantId = (int) (tenant_id() ?? 0);
        if ($tenantId <= 0) {
            throw new InvalidArgumentException('Tenant context required');
        }

        $this->assertWarehouse($tenantId, $warehouseId);
        $weight = $this->bcNormalize($weightKg, 3);

        foreach ([WarehouseBin::ZONE_RECEIVING, WarehouseBin::ZONE_STORAGE] as $zone) {
            $candidates = WarehouseBin::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('warehouse_id', $warehouseId)
                ->where('zone', $zone)
                ->where('is_active', true)
                ->orderBy('code')
                ->get();

            foreach ($candidates as $bin) {
                if ($this->fitsWeight($bin, $weight)) {
                    return $bin;
                }
            }
        }

        return null;
    }

    /**
     * FEFO (expiration_date ASC NULLS LAST) then FIFO (received_at ASC).
     * Deducts with bcSub only — no float math on quantities.
     *
     * @return list<array{batch_id: int, bin_id: int|null, deducted_qty: string}>
     */
    public function deductStockFromBins(int $productId, string $requiredQty): array
    {
        $tenantId = (int) (tenant_id() ?? 0);
        if ($tenantId <= 0) {
            throw new InvalidArgumentException('Tenant context required');
        }

        $need = $this->bcNormalize($requiredQty, 3);
        if ($this->bcComp($need, '0', 3) <= 0) {
            throw new InvalidArgumentException('requiredQty must be positive');
        }

        return DB::transaction(function () use ($tenantId, $productId, $need): array {
            $batches = StockBatch::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('product_id', $productId)
                ->whereNotNull('warehouse_bin_id')
                ->where('quantity', '>', 0)
                ->orderByRaw('expiration_date ASC NULLS LAST')
                ->orderBy('received_at', 'asc')
                ->lockForUpdate()
                ->get();

            $availableTotal = '0.000';
            foreach ($batches as $batch) {
                $availableTotal = $this->bcAdd($availableTotal, (string) $batch->quantity, 3);
            }

            if ($this->bcComp($availableTotal, $need, 3) < 0) {
                throw new RuntimeException(
                    "Insufficient bin stock for product {$productId}: need {$need}, available {$availableTotal}"
                );
            }

            $remaining = $need;
            $deductions = [];

            foreach ($batches as $batch) {
                if ($this->bcComp($remaining, '0', 3) <= 0) {
                    break;
                }

                $onHand = $this->bcNormalize((string) $batch->quantity, 3);
                if ($this->bcComp($onHand, '0', 3) <= 0) {
                    continue;
                }

                $take = $this->bcMin($remaining, $onHand, 3);
                $newQty = $this->bcSub($onHand, $take, 3);

                $batch->forceFill([
                    'quantity' => $newQty,
                    // Keep legacy FIFO remaining_qty in sync when column exists.
                    'remaining_qty' => $newQty,
                ])->save();

                $deductions[] = [
                    'batch_id' => (int) $batch->id,
                    'bin_id' => $batch->warehouse_bin_id !== null ? (int) $batch->warehouse_bin_id : null,
                    'deducted_qty' => $take,
                ];

                $remaining = $this->bcSub($remaining, $take, 3);
            }

            if ($this->bcComp($remaining, '0', 3) > 0) {
                throw new RuntimeException(
                    "Insufficient bin stock for product {$productId}: need {$need}, shortfall {$remaining}"
                );
            }

            return $deductions;
        });
    }

    private function fitsWeight(WarehouseBin $bin, string $weight): bool
    {
        if ($bin->max_weight_kg === null || $bin->max_weight_kg === '') {
            return true;
        }

        if ($this->bcComp($weight, '0', 3) <= 0) {
            return true;
        }

        return $this->bcComp($weight, (string) $bin->max_weight_kg, 3) <= 0;
    }

    private function assertWarehouse(int $tenantId, int $warehouseId): void
    {
        $ok = Warehouse::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereKey($warehouseId)
            ->exists();

        if (! $ok) {
            throw new InvalidArgumentException('Warehouse not found for tenant');
        }
    }
}
