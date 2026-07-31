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

use Autometria\Enums\OrderStatusEnum;
use Autometria\Models\Earning;
use Autometria\Models\OrderItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class KpiService
{
    public function calculateShiftEarnings(int $tenantId, int $masterId, int $shiftId): array
    {
        return DB::transaction(function () use ($tenantId, $masterId, $shiftId): array {
            $orderItems = OrderItem::query()
                ->whereHas('order', function (Builder $query) use ($tenantId, $masterId, $shiftId): void {
                    $query->where('tenant_id', $tenantId)
                        ->where('master_id', $masterId)
                        ->where('shift_id', $shiftId)
                        ->whereIn('status', [
                            OrderStatusEnum::IN_PROGRESS->value,
                            OrderStatusEnum::COMPLETED->value,
                        ]);
                })
                ->get();

            $serviceSum = $orderItems->sum(function (OrderItem $item): float {
                return $item->product->type === 'service' ? $item->summary() : 0.0;
            });

            $earning = Earning::query()
                ->where('tenant_id', $tenantId)
                ->where('master_id', $masterId)
                ->where('shift_id', $shiftId)
                ->firstOrNew();

            $earning->tenant_id = $tenantId;
            $earning->master_id = $masterId;
            $earning->shift_id = $shiftId;
            $earning->service_sum = round($serviceSum, 2);
            $earning->save();

            return [
                'master_id' => $masterId,
                'shift_id' => $shiftId,
                'service_sum' => $earning->service_sum,
                'kpi_amount' => $earning->kpi_amount ?? 0.0,
            ];
        });
    }
}
