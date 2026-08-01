<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @package    Autometria\Core
 * @copyright  (c) 2026 Себиев Ахмед Сулейманович (Sebiev Akhmed Suleymanovich). All Rights Reserved.
 * @author     Себиев Ахмед Сулейманович (Chief Software Architect / Lead Developer)
 * @license    Proprietary & Confidential. Unauthorized copying, distribution,
 *             modification, or reverse engineering of this file, via any medium,
 *             is strictly prohibited.
 *
 * NOTICE: All information contained herein is, and remains the property of
 * Себиев Ахмед Сулейманович. The intellectual and technical concepts contained
 * herein are proprietary and protected by trade secret and copyright law.
 */
/**
 * LASTIK B2B SaaS Engine Core
 *
 * @copyright  (c) 2026 Себиев Ахмед Сулейманович (Sebiev Akhmed Suleymanovich). All Rights Reserved.
 * @author     Себиев Ахмед Сулейманович (Chief Software Architect / Lead Developer)
 * @license    Proprietary & Confidential. Unauthorized copying, distribution,
 *             modification, or reverse engineering of this file, via any medium,
 *             is strictly prohibited.
 *
 * NOTICE: All information contained herein is, and remains the property of
 * Себиев Ахмед Сулейманович. The intellectual and technical concepts contained
 * herein are proprietary and protected by trade secret and copyright law.
 */
/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author Себиев Ахмед Сулейманович
 * @license Proprietary & Confidential.
 */

declare(strict_types=1);

namespace Autometria\Services;

use Autometria\Enums\StockTransferStatus;
use Autometria\Exceptions\Domain\InsufficientStockException;
use Autometria\Models\Stock;
use Autometria\Models\StockConflict;
use Autometria\Models\StockTransfer;
use Autometria\Support\AuditLog;
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
            set_current_tenant_id($tenantId);

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
                $to = Stock::query()->withoutGlobalScopes()->forceCreate([
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
                StockConflict::query()->withoutGlobalScopes()->forceCreate([
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

            $transfer = StockTransfer::query()->withoutGlobalScopes()->forceCreate([
                'tenant_id' => $tenantId,
                'product_id' => $productId,
                'from_warehouse_id' => $fromWarehouseId,
                'to_warehouse_id' => $toWarehouseId,
                'qty' => $qty,
                'reason' => $reason,
                'created_by' => $createdBy,
                'status' => StockTransferStatus::COMPLETED->value,
                'shipped_at' => now(),
                'received_at' => now(),
                'shipped_by' => $createdBy,
                'received_by' => $createdBy,
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

    /**
     * Отгрузка (DRAFT -> IN_TRANSIT): резервирует товар на исходном складе,
     * но НЕ списывает его (он остаётся на source до приёмки).
     */
    public function ship(int $tenantId, int $transferId, int $shippedBy): StockTransfer
    {
        return DB::transaction(function () use ($tenantId, $transferId, $shippedBy): StockTransfer {
            $transfer = StockTransfer::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereKey($transferId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($transfer->status !== StockTransferStatus::DRAFT) {
                throw new InvalidArgumentException('Transfer must be in DRAFT to ship');
            }

            $from = Stock::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('warehouse_id', $transfer->from_warehouse_id)
                ->where('product_id', $transfer->product_id)
                ->lockForUpdate()
                ->first();

            if ($from === null || (float) $from->available < (float) $transfer->qty) {
                throw new InsufficientStockException('available_less_than_qty');
            }

            // Резервируем под перемещение (уменьшаем available, но не actual).
            $from->reserved = (float) $from->reserved + (float) $transfer->qty;
            $from->available = (float) $from->actual - (float) $from->reserved;
            $from->save();

            $transfer->status = StockTransferStatus::IN_TRANSIT;
            $transfer->shipped_by = $shippedBy;
            $transfer->shipped_at = now();
            $transfer->save();

            AuditLog::write($tenantId, $shippedBy, 'stock.transfer.shipped', StockTransfer::class, (int) $transfer->id,
                [], ['qty' => (float) $transfer->qty]);

            return $transfer;
        });
    }

    /**
     * Приёмка (IN_TRANSIT -> COMPLETED): списывает с source, оприходывает на destination.
     */
    public function receive(int $tenantId, int $transferId, int $receivedBy): StockTransfer
    {
        return DB::transaction(function () use ($tenantId, $transferId, $receivedBy): StockTransfer {
            $transfer = StockTransfer::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereKey($transferId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($transfer->status !== StockTransferStatus::IN_TRANSIT) {
                throw new InvalidArgumentException('Transfer must be IN_TRANSIT to receive');
            }

            $qty = (float) $transfer->qty;

            // Source: снимаем резерв и уменьшаем actual (товар уехал).
            $from = Stock::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('warehouse_id', $transfer->from_warehouse_id)
                ->where('product_id', $transfer->product_id)
                ->lockForUpdate()
                ->firstOrFail();

            $from->actual = (float) $from->actual - $qty;
            $from->reserved = max(0.0, (float) $from->reserved - $qty);
            $from->available = (float) $from->actual - (float) $from->reserved;
            $from->save();

            // Destination: оприходование.
            $to = Stock::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('warehouse_id', $transfer->to_warehouse_id)
                ->where('product_id', $transfer->product_id)
                ->lockForUpdate()
                ->first();

            if ($to === null) {
                $to = Stock::query()->withoutGlobalScopes()->forceCreate([
                    'tenant_id' => $tenantId,
                    'warehouse_id' => $transfer->to_warehouse_id,
                    'product_id' => $transfer->product_id,
                    'actual' => 0,
                    'reserved' => 0,
                    'available' => 0,
                ]);
                $to = Stock::query()->withoutGlobalScopes()->whereKey($to->id)->lockForUpdate()->firstOrFail();
            }

            $to->actual = (float) $to->actual + $qty;
            $to->available = (float) $to->actual - (float) $to->reserved;
            $to->save();

            $transfer->status = StockTransferStatus::COMPLETED;
            $transfer->received_by = $receivedBy;
            $transfer->received_at = now();
            $transfer->save();

            AuditLog::write($tenantId, $receivedBy, 'stock.transfer.received', StockTransfer::class, (int) $transfer->id,
                [], ['qty' => $qty, 'to_warehouse_id' => $transfer->to_warehouse_id]);

            return $transfer;
        });
    }
}
