<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\Domain\ShiftAlreadyOpenedException;
use App\Models\CashShift;
use App\Support\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CashShiftController extends Controller
{
    public function index(): array
    {
        return ['data' => CashShift::all()];
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $user = $request->user();
        $tenantId = (int) ($request->input('tenant_id') ?? $user?->tenant_id);
        $locationId = (int) ($request->input('location_id') ?? $user?->location_id);

        $existing = CashShift::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereNull('closed_at')
            ->where(function ($q): void {
                $q->where('status', 'opened')->orWhereNull('status');
            })
            ->first();

        if ($existing !== null) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json([
                    'message' => 'Смена уже открыта',
                    'data' => $existing,
                ], 409);
            }

            throw ShiftAlreadyOpenedException::default();
        }

        $shift = CashShift::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenantId,
            'location_id' => $locationId ?: null,
            'user_id' => $user?->id,
            'opened_by' => $user?->id,
            'status' => 'opened',
            'opening_amount' => (float) $request->input('opening_amount', 0),
            'opened_at' => now(),
            'note' => $request->input('note'),
        ]);

        AuditLog::write(
            $tenantId,
            $user?->id,
            'cash_shift.open',
            CashShift::class,
            (int) $shift->id,
            [],
            ['status' => 'opened'],
            ['location_id' => $locationId],
        );

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json(['data' => $shift], 201);
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

        $shift->update([
            'closed_at' => now(),
            'status' => 'closed',
            'closed_by' => auth()->id(),
        ]);

        AuditLog::write(
            (int) $shift->tenant_id,
            auth()->id(),
            'cash_shift.close',
            CashShift::class,
            (int) $shift->id,
            ['status' => 'opened'],
            ['status' => 'closed'],
        );

        return ['data' => $shift->fresh()];
    }
}
