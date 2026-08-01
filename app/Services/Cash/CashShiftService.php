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

use Autometria\Enums\ShiftStatusEnum;
use Autometria\Exceptions\Domain\ShiftAlreadyClosedException;
use Autometria\Exceptions\Domain\ShiftExpiredException;
use Autometria\Models\CashMovement;
use Autometria\Models\CashShift;
use Autometria\Models\Payment;
use Autometria\Support\AuditLog;
use Illuminate\Support\Facades\DB;

class CashShiftService
{
    public const MAX_SHIFT_DURATION_HOURS = 24;

    public function open(int $tenantId, int $locationId, int $userId, float $initialBalance = 0.0): CashShift
    {
        return DB::transaction(function () use ($tenantId, $locationId, $userId, $initialBalance): CashShift {
            $now = now();

            /** @var CashShift|null $opened */
            $opened = CashShift::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('location_id', $locationId)
                ->whereNull('closed_at')
                ->where('status', ShiftStatusEnum::OPENED->value)
                ->lockForUpdate()
                ->first();

            if ($opened !== null) {
                // Re-check expiry on an already-open shift before returning it.
                $this->assertShiftActive($opened);

                return $opened;
            }

            $shift = CashShift::query()->withoutGlobalScopes()->forceCreate([
                'tenant_id' => $tenantId,
                'location_id' => $locationId,
                'user_id' => $userId,
                'opened_by' => $userId,
                'status' => ShiftStatusEnum::OPENED->value,
                'opening_amount' => round($initialBalance, 2),
                'opened_at' => $now,
                'expires_at' => $now->copy()->addHours(self::MAX_SHIFT_DURATION_HOURS),
                'closed_at' => null,
                'totals' => [
                    'cash' => 0,
                    'card' => 0,
                    'transfer' => 0,
                    'online' => 0,
                    'inkasso' => 0,
                    'withdrawal' => 0,
                    'correction' => 0,
                    'deposit' => 0,
                ],
            ]);

            AuditLog::write($tenantId, $userId, 'cash_shift.open', CashShift::class, (int) $shift->id,
                [], ['opening_amount' => round($initialBalance, 2)]);

            return $shift;
        });
    }

    /**
     * Guard: throws ShiftExpiredException if the shift has been open longer than 24h.
     * Auto-transitions the shift to EXPIRED before throwing.
     */
    public function assertShiftActive(CashShift $shift): void
    {
        if ($shift->closed_at !== null || $shift->status === ShiftStatusEnum::CLOSED->value) {
            throw ShiftAlreadyClosedException::default();
        }

        if ($shift->status === ShiftStatusEnum::EXPIRED->value) {
            throw new ShiftExpiredException();
        }

        $openedAt = $shift->opened_at instanceof \DateTimeInterface
            ? \Carbon\Carbon::parse($shift->opened_at)
            : null;

        if ($openedAt !== null && $openedAt->copy()->addHours(self::MAX_SHIFT_DURATION_HOURS)->isPast()) {
            // Auto-expire the shift (cannot be used for new operations).
            $shift->status = ShiftStatusEnum::EXPIRED->value;
            $shift->save();

            AuditLog::write(
                (int) $shift->tenant_id,
                auth()->id() ?? $shift->user_id,
                'cash_shift.expired',
                CashShift::class,
                (int) $shift->id,
                [],
                ['opened_at' => $openedAt->toIso8601String()],
            );

            throw new ShiftExpiredException();
        }
    }

    public function close(CashShift $shift, ?float $actualCash = null): CashShift
    {
        return DB::transaction(function () use ($shift, $actualCash): CashShift {
            /** @var CashShift $shift */
            $shift = CashShift::query()->withoutGlobalScopes()
                ->whereKey($shift->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($shift->closed_at !== null || $shift->status === ShiftStatusEnum::CLOSED->value) {
                throw ShiftAlreadyClosedException::default();
            }

            $this->assertShiftActive($shift);

            $totals = $this->calculateTotals($shift);

            $opening = (float) ($shift->opening_amount ?? 0);
            $expectedCash = round(
                $opening
                + (float) ($totals['cash'] ?? 0)
                + (float) ($totals['deposit'] ?? 0)
                - (float) ($totals['inkasso'] ?? 0)
                - (float) ($totals['withdrawal'] ?? 0),
                2
            );

            // Reconciliation: shortage (не хватает) / overage (излишек).
            $shortage = 0.0;
            $overage = 0.0;
            if ($actualCash !== null) {
                $diff = round((float) $actualCash - $expectedCash, 2);
                if ($diff < 0) {
                    $shortage = abs($diff);
                } elseif ($diff > 0) {
                    $overage = $diff;
                }
            }

            $zReport = [
                'opened_at' => optional($shift->opened_at)->toIso8601String(),
                'closed_at' => now()->toIso8601String(),
                'opening_amount' => $opening,
                'expected_cash' => $expectedCash,
                'actual_cash' => $actualCash !== null ? round($actualCash, 2) : null,
                'shortage' => $shortage,
                'overage' => $overage,
                'totals' => $totals,
            ];

            $shift->closed_at = now();
            $shift->status = ShiftStatusEnum::CLOSED->value;
            $shift->closed_by = auth()->id();
            $shift->expected_cash = $expectedCash;
            $shift->shortage = $shortage;
            $shift->overage = $overage;
            $shift->closing_amount = $actualCash !== null ? round($actualCash, 2) : $expectedCash;
            $shift->z_report = $zReport;
            $shift->totals = array_merge($shift->totals ?? [], $totals);
            $shift->save();

            AuditLog::write(
                (int) $shift->tenant_id,
                auth()->id(),
                'cash_shift.close',
                CashShift::class,
                (int) $shift->id,
                [],
                ['totals' => $totals, 'shortage' => $shortage, 'overage' => $overage, 'z_report' => $zReport],
            );

            return $shift;
        });
    }

    public function deposit(CashShift $shift, float $amount, ?string $reason = null): CashMovement
    {
        $this->assertShiftActive($shift);

        if ($amount <= 0) {
            throw new \InvalidArgumentException('Deposit amount must be positive');
        }

        return DB::transaction(function () use ($shift, $amount, $reason) {
            $shift = CashShift::query()->withoutGlobalScopes()
                ->whereKey($shift->id)
                ->lockForUpdate()
                ->firstOrFail();

            $movement = CashMovement::query()->withoutGlobalScopes()->forceCreate([
                'tenant_id' => $shift->tenant_id,
                'shift_id' => $shift->id,
                'type' => CashMovement::TYPE_ADJUSTMENT,
                'amount' => $amount,
                'reason' => $reason,
                'note' => $reason,
                'created_by' => auth()->id(),
                'payee_id' => auth()->id(),
            ]);

            $totals = $shift->totals ?? [];
            $totals['deposit'] = round(($totals['deposit'] ?? 0) + $amount, 2);
            $shift->update(['totals' => $totals]);

            AuditLog::write(
                (int) $shift->tenant_id,
                auth()->id(),
                'cash_shift.deposit',
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

    public function inkasso(CashShift $shift, float $amount, ?string $reason = null): CashMovement
    {
        $this->assertShiftActive($shift);

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
        $this->assertShiftActive($shift);

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

    /**
     * Background reconciliation: find OPENED shifts whose opened_at is older than 24h
     * and transition them to EXPIRED. Returns the number of shifts expired.
     */
    public function expireOverdueShifts(): int
    {
        $cutoff = now()->subHours(self::MAX_SHIFT_DURATION_HOURS);

        $overdue = CashShift::query()->withoutGlobalScopes()
            ->where('status', ShiftStatusEnum::OPENED->value)
            ->whereNull('closed_at')
            ->where('opened_at', '<=', $cutoff)
            ->get();

        $count = 0;
        foreach ($overdue as $shift) {
            $shift->status = ShiftStatusEnum::EXPIRED->value;
            $shift->save();

            AuditLog::write(
                (int) $shift->tenant_id,
                $shift->user_id,
                'cash_shift.expired',
                CashShift::class,
                (int) $shift->id,
                [],
                ['opened_at' => optional($shift->opened_at)->toIso8601String(), 'auto' => true],
            );
            $count++;
        }

        return $count;
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
        $totals['deposit'] = round(
            (float) CashMovement::query()->withoutGlobalScopes()
                ->where('shift_id', $shift->id)
                ->where('type', CashMovement::TYPE_ADJUSTMENT)
                ->sum('amount'),
            2
        );

        return $totals;
    }
}
