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

use Autometria\Exceptions\Domain\InsufficientStockException;
use Autometria\Models\Reservation;
use Autometria\Models\Stock;
use Autometria\Services\Reservation\InvalidReservationException;
use Autometria\Support\AuditLog;
use Illuminate\Support\Facades\DB;

class StockReservationService
{
    public function reserve(int $stockId, int $tenantId, float|int $qty, ?int $orderItemId = null): Reservation
    {
        return DB::transaction(function () use ($stockId, $tenantId, $qty, $orderItemId) {
            // Pessimistic lock MUST be on a fresh Builder query (not $stock->lockForUpdate()).
            $stock = Stock::query()->withoutGlobalScopes()
                ->whereKey($stockId)
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->firstOrFail();

            $qty = (float) $qty;
            $newAvailable = (float) $stock->available - $qty;

            if ($newAvailable < 0) {
                throw new InsufficientStockException('Available cannot be negative');
            }

            $stock->reserved = (float) $stock->reserved + $qty;
            $stock->available = $newAvailable;
            $stock->save();

            $reservation = Reservation::query()->withoutGlobalScopes()->forceCreate([
                'tenant_id' => $tenantId,
                'stock_id' => $stock->id,
                'order_item_id' => $orderItemId,
                'qty' => $qty,
                'status' => Reservation::STATUS_ACTIVE,
            ]);

            AuditLog::write(
                $tenantId,
                auth()->id(),
                'stock.reserved',
                Reservation::class,
                (int) $reservation->id,
                ['available' => $newAvailable + $qty],
                [
                    'qty' => $qty,
                    'stock_id' => $stock->id,
                    'order_item_id' => $orderItemId,
                    'available' => $newAvailable,
                ],
            );

            return $reservation;
        });
    }

    public function release(int $stockId, int $tenantId, float|int $qty, ?int $orderItemId = null): Reservation
    {
        return DB::transaction(function () use ($stockId, $tenantId, $qty, $orderItemId) {
            $stock = Stock::query()->withoutGlobalScopes()
                ->whereKey($stockId)
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->firstOrFail();

            $qty = (float) $qty;
            $newReserved = (float) $stock->reserved - $qty;

            if ($newReserved < 0) {
                throw new InvalidReservationException('Reserved cannot be negative');
            }

            $stock->reserved = $newReserved;
            $stock->available = (float) $stock->actual - $newReserved;
            $stock->save();

            $active = null;
            if ($orderItemId) {
                $active = Reservation::query()->withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('order_item_id', $orderItemId)
                    ->where('stock_id', $stockId)
                    ->where('status', Reservation::STATUS_ACTIVE)
                    ->lockForUpdate()
                    ->first();
            }

            if ($active !== null) {
                $active->update(['status' => Reservation::STATUS_RELEASED]);
                $reservation = $active->fresh();
            } else {
                $reservation = Reservation::query()->withoutGlobalScopes()->forceCreate([
                    'tenant_id' => $tenantId,
                    'stock_id' => $stock->id,
                    'order_item_id' => $orderItemId,
                    'qty' => $qty,
                    'status' => Reservation::STATUS_RELEASED,
                ]);
            }

            AuditLog::write(
                $tenantId,
                auth()->id(),
                'stock.released',
                Reservation::class,
                (int) $reservation->id,
                [],
                [
                    'qty' => $qty,
                    'stock_id' => $stock->id,
                    'order_item_id' => $orderItemId,
                    'available' => $stock->available,
                ],
            );

            return $reservation;
        });
    }
}
