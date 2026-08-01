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

use Autometria\Exceptions\Domain\OverpaymentException;
use Autometria\Exceptions\Domain\ShiftAlreadyClosedException;
use Autometria\Models\Payment;
use Autometria\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentService $payments,
    ) {}

    public function index(Request $request): array
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $tenantId = (int) ($user->tenant_id ?? tenant_id() ?? 0);
        $locationId = location_id() ?? ($user->location_id ? (int) $user->location_id : null);
        abort_unless($tenantId > 0, 422, 'Tenant context required');

        $query = Payment::query()->where('tenant_id', $tenantId);

        if ($locationId !== null) {
            $query->whereHas('order', fn ($q) => $q->where('location_id', $locationId));
        }

        return ['data' => $query->latest('id')->get()];
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Payment::class);

        $validated = $request->validate([
            'tenant_id' => ['prohibited'],
            'location_id' => ['prohibited'],
            'created_by' => ['prohibited'],
            'order_id' => ['required', 'integer', 'exists:orders,id'],
            'parts' => ['required', 'array', 'min:1'],
            'parts.*.method' => ['required', 'string', 'max:40'],
            'parts.*.amount' => ['required', 'numeric', 'min:0.01'],
            'parts.*.payee_id' => ['nullable', 'integer', 'exists:users,id'],
            'shift_id' => ['nullable', 'integer'],
        ]);

        try {
            $created = $this->payments->accept(
                (int) ($request->user()->tenant_id ?? tenant_id() ?? 0),
                (int) $validated['order_id'],
                $validated['parts'],
                (int) $request->user()->id,
                isset($validated['shift_id']) ? (int) $validated['shift_id'] : null,
            );
        } catch (OverpaymentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(['data' => $created], 201);
    }

    public function correct(Request $request, Payment $payment): JsonResponse
    {
        $this->authorize('correct', $payment);

        $validated = $request->validate([
            'tenant_id' => ['prohibited'],
            'location_id' => ['prohibited'],
            'new_amount' => ['required', 'numeric', 'min:0'],
            'reason' => ['required', 'string', 'min:3'],
        ]);

        try {
            $correction = $this->payments->correct(
                $payment,
                (float) $validated['new_amount'],
                $validated['reason'],
                (int) $request->user()->id,
            );
        } catch (ShiftAlreadyClosedException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(['data' => $correction], 201);
    }
}
