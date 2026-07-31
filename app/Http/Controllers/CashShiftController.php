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

        $query = CashShift::query()
            ->where('tenant_id', $tenantId);

        if ($locationId !== null) {
            $query->where('location_id', $locationId);
        }

        return ['data' => $query->latest('id')->get()];
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
            $shift = $this->shifts->open($tenantId, $locationId, (int) $user->id);

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
