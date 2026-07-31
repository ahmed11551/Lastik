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

use Autometria\Models\Customer;
use Autometria\Models\CustomerMerge;
use Autometria\Models\Order;
use Autometria\Models\Payment;
use Autometria\Models\Vehicle;
use Autometria\Support\AuditLog;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class CustomerMergeService
{
    public function merge(
        int $tenantId,
        int $primaryId,
        int $duplicateId,
        int $mergedBy,
        ?string $reason = null,
    ): CustomerMerge {
        if ($primaryId === $duplicateId) {
            throw new InvalidArgumentException('Cannot merge customer into itself');
        }

        return DB::transaction(function () use ($tenantId, $primaryId, $duplicateId, $mergedBy, $reason): CustomerMerge {
            $primary = Customer::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)->whereKey($primaryId)->lockForUpdate()->firstOrFail();
            $duplicate = Customer::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)->whereKey($duplicateId)->lockForUpdate()->firstOrFail();

            $ordersMoved = Order::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('customer_id', $duplicate->id)
                ->update(['customer_id' => $primary->id]);

            $vehiclesMoved = Vehicle::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('customer_id', $duplicate->id)
                ->update(['customer_id' => $primary->id]);

            // Платежи связаны через заказы — история сохраняется через перенос orders
            $paymentsKept = Payment::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereIn('order_id', Order::query()->withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('customer_id', $primary->id)
                    ->select('id'))
                ->count();

            $transferred = [
                'orders' => $ordersMoved,
                'vehicles' => $vehiclesMoved,
                'payments_visible_via_orders' => $paymentsKept,
                'duplicate_snapshot' => $duplicate->only([
                    'id', 'type', 'name', 'legal_name', 'phone', 'email', 'inn', 'kpp',
                ]),
            ];

            $merge = CustomerMerge::query()->withoutGlobalScopes()->create([
                'tenant_id' => $tenantId,
                'primary_customer_id' => $primary->id,
                'merged_customer_id' => $duplicate->id,
                'merged_by' => $mergedBy,
                'transferred' => $transferred,
                'reason' => $reason,
            ]);

            // Дубликат помечаем как неактивный юридически — soft-delete нет, обнуляем телефон чтобы не мешал поиску
            $duplicate->update([
                'phone' => ($duplicate->phone ? $duplicate->phone.'-merged-'.$duplicate->id : null),
                'email' => null,
            ]);

            AuditLog::write(
                $tenantId,
                $mergedBy,
                'customer.merged',
                Customer::class,
                (int) $primary->id,
                ['merged_customer_id' => $duplicate->id],
                $transferred,
                [],
                $reason,
            );

            return $merge;
        });
    }
}
