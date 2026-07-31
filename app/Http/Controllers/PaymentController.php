<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Services\Cash\CashShiftService;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class PaymentController extends Controller
{
    public function __construct(
        private readonly CashShiftService $cashShifts,
        private readonly PaymentService $payments,
    ) {}

    public function index(): array
    {
        return ['data' => Payment::all()];
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
            'parts.*.method' => ['required', 'string', 'in:cash,card,transfer,sbp,terminal'],
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
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(['data' => $created], 201);
    }

    public function correct(Request $request, Payment $payment): JsonResponse
    {
        $this->authorize('correct', $payment);

        $validated = $request->validate([
            'new_amount' => ['required', 'numeric', 'min:0'],
            'reason' => ['required', 'string', 'min:3'],
        ]);

        // После закрытия смены прямая правка запрещена — только correction workflow
        if ($payment->shift_id && $this->cashShifts->isClosed((int) $payment->shift_id)) {
            $correction = $this->payments->correct(
                $payment,
                (float) $validated['new_amount'],
                $validated['reason'],
                (int) $request->user()->id,
            );

            return response()->json(['data' => $correction, 'via' => 'correction'], 201);
        }

        $correction = $this->payments->correct(
            $payment,
            (float) $validated['new_amount'],
            $validated['reason'],
            (int) $request->user()->id,
        );

        return response()->json(['data' => $correction], 201);
    }
}
