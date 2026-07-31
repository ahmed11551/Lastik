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

namespace Autometria\Services\Cash;

use Autometria\Exceptions\Domain\ShiftAlreadyClosedException;
use Autometria\Models\CashMovement;
use Autometria\Models\CashShift;
use Autometria\Models\Payment;
use Autometria\Support\AuditLog;
use Illuminate\Support\Facades\DB;

class CashShiftService
{
    public function open(int $tenantId, int $locationId, int $userId): CashShift
    {
        return DB::transaction(function () use ($tenantId, $locationId, $userId): CashShift {
            $now = now();

            /** @var CashShift|null $opened */
            $opened = CashShift::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('location_id', $locationId)
                ->whereNull('closed_at')
                ->lockForUpdate()
                ->first();

            if ($opened !== null) {
                return $opened;
            }

            $shift = CashShift::query()->withoutGlobalScopes()->forceCreate([
                'tenant_id' => $tenantId,
                'location_id' => $locationId,
                'user_id' => $userId,
                'opened_by' => $userId,
                'status' => 'opened',
                'opened_at' => $now,
                'closed_at' => null,
                'totals' => [
                    'cash' => 0,
                    'card' => 0,
                    'transfer' => 0,
                    'online' => 0,
                    'inkasso' => 0,
                    'withdrawal' => 0,
                    'correction' => 0,
                ],
            ]);

            AuditLog::write($tenantId, $userId, 'cash_shift.open', CashShift::class, (int) $shift->id);

            return $shift;
        });
    }

    public function close(CashShift $shift): CashShift
    {
        return DB::transaction(function () use ($shift) {
            /** @var CashShift $shift */
            $shift = CashShift::query()->withoutGlobalScopes()
                ->whereKey($shift->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($shift->closed_at !== null || $shift->status === 'closed') {
                throw ShiftAlreadyClosedException::default();
            }

            $totals = $this->calculateTotals($shift);

            $shift->closed_at = now();
            $shift->status = 'closed';
            $shift->closed_by = auth()->id();
            $shift->totals = array_merge($shift->totals ?? [], $totals);
            $shift->save();

            AuditLog::write(
                (int) $shift->tenant_id,
                auth()->id(),
                'cash_shift.close',
                CashShift::class,
                (int) $shift->id,
                [],
                ['totals' => $shift->totals],
            );

            return $shift;
        });
    }

    public function inkasso(CashShift $shift, float $amount, ?string $reason = null): CashMovement
    {
        if ($shift->closed_at !== null) {
            throw new \RuntimeException('Shift is closed, inkasso is not allowed');
        }

        if ($amount <= 0) {
            throw new \InvalidArgumentException('Inkasso amount must be positive');
        }

        return DB::transaction(function () use ($shift, $amount, $reason) {
            $shift = CashShift::query()->withoutGlobalScopes()
                ->whereKey($shift->id)
                ->lockForUpdate()
                ->firstOrFail();

            $movement = CashMovement::query()->withoutGlobalScopes()->forceCreate([
                'tenant_id' => $shift->tenant_id,
                'shift_id' => $shift->id,
                'type' => CashMovement::TYPE_INKASSO,
                'amount' => $amount,
                'reason' => $reason,
                'note' => $reason,
                'created_by' => auth()->id(),
                'payee_id' => auth()->id(),
            ]);

            $totals = $shift->totals ?? [];
            $totals['inkasso'] = round(($totals['inkasso'] ?? 0) + $amount, 2);
            $shift->update(['totals' => $totals]);

            AuditLog::write(
                (int) $shift->tenant_id,
                auth()->id(),
                'cash_shift.inkasso',
                CashMovement::class,
                (int) $movement->id,
                [],
                ['amount' => $amount, 'shift_id' => $shift->id],
                [],
                $reason,
            );

            return $movement;
        });
    }

    public function withdrawal(CashShift $shift, float $amount, ?string $reason = null): CashMovement
    {
        if ($shift->closed_at !== null) {
            throw new \RuntimeException('Shift is closed, withdrawal is not allowed');
        }

        if ($amount <= 0) {
            throw new \InvalidArgumentException('Withdrawal amount must be positive');
        }

        return DB::transaction(function () use ($shift, $amount, $reason) {
            $shift = CashShift::query()->withoutGlobalScopes()
                ->whereKey($shift->id)
                ->lockForUpdate()
                ->firstOrFail();

            $movement = CashMovement::query()->withoutGlobalScopes()->forceCreate([
                'tenant_id' => $shift->tenant_id,
                'shift_id' => $shift->id,
                'type' => CashMovement::TYPE_WITHDRAWAL,
                'amount' => $amount,
                'reason' => $reason,
                'note' => $reason,
                'created_by' => auth()->id(),
                'payee_id' => auth()->id(),
            ]);

            $totals = $shift->totals ?? [];
            $totals['withdrawal'] = round(($totals['withdrawal'] ?? 0) + $amount, 2);
            $shift->update(['totals' => $totals]);

            AuditLog::write(
                (int) $shift->tenant_id,
                auth()->id(),
                'cash_shift.withdrawal',
                CashMovement::class,
                (int) $movement->id,
                [],
                ['amount' => $amount, 'shift_id' => $shift->id],
                [],
                $reason,
            );

            return $movement;
        });
    }

    public function isClosed(int $shiftId): bool
    {
        return CashShift::query()->withoutGlobalScopes()
            ->whereKey($shiftId)
            ->value('closed_at') !== null;
    }

    private function calculateTotals(CashShift $shift): array
    {
        $payments = Payment::query()->withoutGlobalScopes()
            ->where('shift_id', $shift->id)
            ->where('tenant_id', $shift->tenant_id)
            ->get()
            ->groupBy('method');

        $totals = [];
        foreach ($payments as $method => $group) {
            $totals[$method] = round((float) $group->sum('amount'), 2);
        }

        $totals['inkasso'] = round(
            (float) CashMovement::query()->withoutGlobalScopes()
                ->where('shift_id', $shift->id)
                ->where('type', CashMovement::TYPE_INKASSO)
                ->sum('amount'),
            2
        );
        $totals['withdrawal'] = round(
            (float) CashMovement::query()->withoutGlobalScopes()
                ->where('shift_id', $shift->id)
                ->where('type', CashMovement::TYPE_WITHDRAWAL)
                ->sum('amount'),
            2
        );

        return $totals;
    }
}
