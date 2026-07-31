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

use Autometria\Exceptions\Domain\NoActiveShiftException;
use Autometria\Exceptions\Domain\ShiftAlreadyOpenedException;
use Autometria\Models\CashMovement;
use Autometria\Models\CashShift;
use Autometria\Models\Payment;
use Illuminate\Support\Facades\DB;

class ShiftManagementService
{
    public function open(int $tenantId, int $userId, ?int $locationId = null, ?string $note = null): CashShift
    {
        return DB::transaction(function () use ($tenantId, $userId, $note): CashShift {
            $existing = CashShift::query()
                ->where('tenant_id', $tenantId)
                ->where('status', 'opened')
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                throw ShiftAlreadyOpenedException::default();
            }

            return CashShift::create([
                'tenant_id' => $tenantId,
                'opened_by' => $userId,
                'opening_amount' => 0,
                'status' => 'opened',
                'note' => $note,
            ]);
        });
    }

    public function close(int $tenantId, int $userId, float $actualCash, ?string $note = null): CashShift
    {
        return DB::transaction(function () use ($tenantId, $userId, $actualCash, $note): CashShift {
            $shift = CashShift::query()
                ->where('tenant_id', $tenantId)
                ->where('status', 'opened')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($shift === null) {
                throw NoActiveShiftException::default();
            }

            $paymentsTotal = Payment::query()
                ->where('tenant_id', $tenantId)
                ->where('shift_id', $shift->id)
                ->where('status', '!=', 'cancelled')
                ->sum('amount');

            $cashIn = CashMovement::query()
                ->where('tenant_id', $tenantId)
                ->where('shift_id', $shift->id)
                ->whereIn('type', [CashMovement::TYPE_INKASSO, CashMovement::TYPE_ADJUSTMENT])
                ->sum('amount');

            $cashOut = CashMovement::query()
                ->where('tenant_id', $tenantId)
                ->where('shift_id', $shift->id)
                ->where('type', CashMovement::TYPE_WITHDRAWAL)
                ->sum('amount');

            $expectedCash = round((float) $shift->opening_amount + (float) $cashIn - (float) $cashOut, 2);
            $cashDifference = round($actualCash - $expectedCash, 2);

            $shift->closed_by = $userId;
            $shift->closed_at = now();
            $shift->closing_amount = round($actualCash, 2);
            $shift->status = 'closed';
            $shift->note = $note ?: $shift->note;
            $shift->save();

            return $shift;
        });
    }

    public function activeForCashier(int $tenantId, int $userId): ?CashShift
    {
        return CashShift::query()
            ->where('tenant_id', $tenantId)
            ->where('opened_by', $userId)
            ->where('status', 'opened')
            ->latest('id')
            ->first();
    }
}
