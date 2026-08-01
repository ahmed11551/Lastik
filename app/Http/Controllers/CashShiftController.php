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

namespace Autometria\Http\Controllers;

use Autometria\Exceptions\Domain\ShiftAlreadyClosedException;
use Autometria\Exceptions\Domain\ShiftAlreadyOpenedException;
use Autometria\Exceptions\Domain\ShiftExpiredException;
use Autometria\Models\CashShift;
use Autometria\Models\Location;
use Autometria\Services\Cash\CashShiftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

class CashShiftController extends Controller
{
    public function __construct(
        private readonly CashShiftService $shifts,
    ) {}

    public function index(Request $request): array
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $tenantId = (int) ($user->tenant_id ?? tenant_id() ?? 0);
        $locationId = location_id() ?? ($user->location_id ? (int) $user->location_id : null);
        abort_unless($tenantId > 0, 422, 'Tenant context required');

        if ($locationId !== null) {
            abort_unless(
                Location::query()->whereKey($locationId)->where('tenant_id', $tenantId)->exists(),
                403,
                'Location does not belong to current tenant',
            );
        }

        $query = CashShift::query()->where('tenant_id', $tenantId);
        if ($locationId !== null) {
            $query->where('location_id', $locationId);
        }

        return ['data' => $query->latest('id')->get()];
    }

    public function current(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $tenantId = (int) ($user->tenant_id ?? tenant_id() ?? 0);
        $locationId = (int) (location_id() ?? $user->location_id ?? 0);
        abort_unless($tenantId > 0 && $locationId > 0, 422, 'Tenant/location context required');

        $shift = CashShift::query()
            ->where('tenant_id', $tenantId)
            ->where('location_id', $locationId)
            ->whereNull('closed_at')
            ->latest('id')
            ->first();

        $revenue = 0.0;
        $expectedCash = 0.0;
        $totals = [];
        if ($shift) {
            $totals = is_array($shift->totals) ? $shift->totals : [];
            $revenue = (float) (
                ($totals['cash'] ?? 0)
                + ($totals['card'] ?? 0)
                + ($totals['transfer'] ?? 0)
                + ($totals['online'] ?? 0)
            );
            $opening = (float) ($shift->opening_amount ?? 0);
            $expectedCash = round(
                $opening
                + (float) ($totals['cash'] ?? 0)
                + (float) ($totals['deposit'] ?? 0)
                - (float) ($totals['inkasso'] ?? 0)
                - (float) ($totals['withdrawal'] ?? 0),
                2,
            );
        }

        return response()->json([
            'data' => $shift ? [
                'id' => $shift->id,
                'open' => true,
                'status' => $shift->status,
                'opened_at' => optional($shift->opened_at)?->toIso8601String(),
                'opening_amount' => (float) ($shift->opening_amount ?? 0),
                'revenue' => $revenue,
                'expected_cash' => $expectedCash,
                'totals' => $totals,
                'location_id' => $shift->location_id,
            ] : [
                'open' => false,
                'status' => 'closed',
                'opened_at' => null,
                'opening_amount' => 0,
                'revenue' => 0,
                'expected_cash' => 0,
                'totals' => null,
                'location_id' => $locationId,
            ],
        ]);
    }

    public function movement(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $data = $request->validate([
            'type' => ['required', 'in:deposit,withdrawal,inkasso'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reason' => ['nullable', 'string', 'max:500'],
            'shift_id' => ['nullable', 'integer', 'exists:cash_shifts,id'],
        ]);

        $tenantId = (int) ($user->tenant_id ?? tenant_id() ?? 0);
        $locationId = (int) (location_id() ?? $user->location_id ?? 0);

        $shift = null;
        if (! empty($data['shift_id'])) {
            $shift = CashShift::query()->whereKey((int) $data['shift_id'])->first();
        } else {
            $shift = CashShift::query()
                ->where('tenant_id', $tenantId)
                ->where('location_id', $locationId)
                ->whereNull('closed_at')
                ->latest('id')
                ->first();
        }

        abort_unless($shift !== null, 422, 'Нет открытой смены');
        $this->authorize('close', $shift);

        $amount = (float) $data['amount'];
        $reason = $data['reason'] ?? null;

        try {
            $movement = match ($data['type']) {
                'deposit' => $this->shifts->deposit($shift, $amount, $reason),
                'withdrawal' => $this->shifts->withdrawal($shift, $amount, $reason),
                'inkasso' => $this->shifts->inkasso($shift, $amount, $reason),
            };
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $movement], 201);
    }

    public function openShift(Request $request): JsonResponse|RedirectResponse
    {
        return $this->store($request);
    }

    public function closeCurrent(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $data = $request->validate([
            'shift_id' => ['nullable', 'integer', 'exists:cash_shifts,id'],
            'closing_amount' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $tenantId = (int) ($user->tenant_id ?? tenant_id() ?? 0);
        $locationId = (int) (location_id() ?? $user->location_id ?? 0);

        $shift = null;
        if (! empty($data['shift_id'])) {
            $shift = CashShift::query()->whereKey((int) $data['shift_id'])->first();
        } else {
            $shift = CashShift::query()
                ->where('tenant_id', $tenantId)
                ->where('location_id', $locationId)
                ->whereNull('closed_at')
                ->latest('id')
                ->first();
        }

        abort_unless($shift !== null, 422, 'Нет открытой смены');
        $this->authorize('close', $shift);

        try {
            if (isset($data['closing_amount']) || isset($data['note'])) {
                $shift->forceFill([
                    'closing_amount' => $data['closing_amount'] ?? $shift->closing_amount,
                    'note' => $data['note'] ?? $shift->note,
                ])->save();
            }
            $closed = $this->shifts->close($shift, isset($data['closing_amount']) ? (float) $data['closing_amount'] : null);
        } catch (ShiftAlreadyClosedException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (ShiftExpiredException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $closed]);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $request->validate([
            'tenant_id' => ['prohibited'],
            'location_id' => ['prohibited'],
            'created_by' => ['prohibited'],
            'updated_by' => ['prohibited'],
            'opening_amount' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $user = $request->user();
        abort_unless($user !== null, 401);

        $tenantId = (int) ($user->tenant_id ?? tenant_id() ?? 0);
        $locationId = (int) (location_id() ?? $user->location_id ?? 0);
        abort_unless($tenantId > 0 && $locationId > 0, 422, 'Tenant/location context required');
        abort_unless(
            Location::query()->whereKey($locationId)->where('tenant_id', $tenantId)->exists(),
            403,
            'Location does not belong to current tenant',
        );

        try {
            $shift = $this->shifts->open(
                $tenantId,
                $locationId,
                (int) $user->id,
                (float) $request->input('opening_amount', 0),
            );

            if ($request->filled('opening_amount') || $request->filled('note')) {
                $shift->forceFill([
                    'opening_amount' => (float) $request->input('opening_amount', $shift->opening_amount ?? 0),
                    'note' => $request->input('note', $shift->note),
                ])->save();
            }
        } catch (RuntimeException $e) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['message' => $e->getMessage()], 409);
            }

            throw ShiftAlreadyOpenedException::default();
        }

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json(['data' => $shift->fresh()], 201);
        }

        return redirect()->back()->with('flash', [
            'type' => 'success',
            'message' => 'Смена открыта',
            'shift_id' => $shift->id,
        ]);
    }

    public function close(CashShift $shift): array
    {
        $this->authorize('close', $shift);

        try {
            $closed = $this->shifts->close($shift);
        } catch (ShiftAlreadyClosedException $e) {
            abort(409, $e->getMessage());
        }

        return ['data' => $closed];
    }
}
