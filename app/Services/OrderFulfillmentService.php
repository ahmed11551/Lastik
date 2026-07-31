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
use Autometria\Models\Stock;
use Autometria\Services\Reservation\InvalidReservationException;
use Illuminate\Support\Facades\DB;

/**
 * Списание / корректировка физических остатков (actual/reserved) под блокировкой строки.
 */
final class OrderFulfillmentService
{
    /**
     * Выдача со склада: уменьшает actual и reserved атомарно.
     *
     * @return Stock locked & refreshed
     */
    public function deductIssuedQty(int $stockId, int $tenantId, float $qty): Stock
    {
        if ($qty <= 0) {
            throw new InvalidReservationException('Fulfillment qty must be positive');
        }

        return DB::transaction(function () use ($stockId, $tenantId, $qty): Stock {
            $stock = Stock::query()->withoutGlobalScopes()
                ->whereKey($stockId)
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->firstOrFail();

            $newActual = (float) $stock->actual - $qty;
            $newReserved = (float) $stock->reserved - $qty;

            if ($newActual < -0.0001 || $newReserved < -0.0001) {
                throw new InsufficientStockException('Cannot deduct more than on-hand / reserved');
            }

            $stock->actual = max(0, $newActual);
            $stock->reserved = max(0, $newReserved);
            $stock->available = (float) $stock->actual - (float) $stock->reserved;
            $stock->save();

            return $stock->fresh() ?? $stock;
        });
    }
}
