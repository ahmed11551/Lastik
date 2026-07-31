<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\Domain\InsufficientStockException;
use App\Models\Stock;
use App\Models\StockConflict;
use App\Models\StockTransfer;
use App\Support\AuditLog;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class StockTransferService
{
    public function transfer(
        int $tenantId,
        int $productId,
        int $fromWarehouseId,
        int $toWarehouseId,
        float $qty,
        string $reason,
        int $createdBy,
    ): StockTransfer {
        if ($fromWarehouseId === $toWarehouseId) {
            throw new InvalidArgumentException('Source and destination warehouses must differ');
        }

        if ($qty <= 0) {
            throw new InvalidArgumentException('Transfer qty must be positive');
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('Transfer reason is required');
        }

        return DB::transaction(function () use (
            $tenantId,
            $productId,
            $fromWarehouseId,
            $toWarehouseId,
            $qty,
            $reason,
            $createdBy
        ): StockTransfer {
            app()->instance('current_tenant_id', $tenantId);

            $from = Stock::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('warehouse_id', $fromWarehouseId)
                ->where('product_id', $productId)
                ->lockForUpdate()
                ->first();

            if ($from === null || (float) $from->available < $qty) {
                throw new InsufficientStockException('available_less_than_qty');
            }

            // Нельзя переносить зарезервированное «втихую»: доступный остаток уже actual - reserved
            $from->actual = (float) $from->actual - $qty;
            $from->available = (float) $from->actual - (float) $from->reserved;
            $from->save();

            $to = Stock::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('warehouse_id', $toWarehouseId)
                ->where('product_id', $productId)
                ->lockForUpdate()
                ->first();

            if ($to === null) {
                $to = Stock::query()->withoutGlobalScopes()->create([
                    'tenant_id' => $tenantId,
                    'warehouse_id' => $toWarehouseId,
                    'product_id' => $productId,
                    'actual' => 0,
                    'reserved' => 0,
                    'available' => 0,
                ]);
                $to = Stock::query()->withoutGlobalScopes()->whereKey($to->id)->lockForUpdate()->firstOrFail();
            }

            $to->actual = (float) $to->actual + $qty;
            $to->available = (float) $to->actual - (float) $to->reserved;
            $to->save();

            if ((float) $to->actual + 0.0001 < (float) $to->reserved) {
                StockConflict::query()->withoutGlobalScopes()->create([
                    'tenant_id' => $tenantId,
                    'stock_id' => $to->id,
                    'reason' => 'transfer_reserved_exceeds_actual',
                    'detail' => json_encode([
                        'actual' => $to->actual,
                        'reserved' => $to->reserved,
                        'transfer_qty' => $qty,
                    ]),
                ]);
            }

            $transfer = StockTransfer::query()->withoutGlobalScopes()->create([
                'tenant_id' => $tenantId,
                'product_id' => $productId,
                'from_warehouse_id' => $fromWarehouseId,
                'to_warehouse_id' => $toWarehouseId,
                'qty' => $qty,
                'reason' => $reason,
                'created_by' => $createdBy,
            ]);

            AuditLog::write(
                $tenantId,
                $createdBy,
                'stock.transferred',
                StockTransfer::class,
                (int) $transfer->id,
                [
                    'from_warehouse_id' => $fromWarehouseId,
                    'from_available' => (float) $from->available + $qty,
                ],
                [
                    'to_warehouse_id' => $toWarehouseId,
                    'qty' => $qty,
                    'product_id' => $productId,
                ],
                [],
                $reason,
            );

            return $transfer;
        });
    }
}
