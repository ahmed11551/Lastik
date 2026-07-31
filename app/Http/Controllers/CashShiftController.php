<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\Domain\ShiftAlreadyOpenedException;
use App\Models\CashShift;
use App\Services\Cash\CashShiftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

class CashShiftController extends Controller
{
    public function __construct(
        private readonly CashShiftService $shifts,
    ) {}

    public function index(): array
    {
        return ['data' => CashShift::all()];
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

        $closed = $this->shifts->close($shift);

        return ['data' => $closed];
    }
}
