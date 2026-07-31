<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Issuance;
use App\Models\Order;
use App\Services\IssuanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IssuanceController extends Controller
{
    public function __construct(
        private readonly IssuanceService $issuances,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order_id' => ['required', 'integer', 'exists:orders,id'],
            'order_item_id' => ['required', 'integer', 'exists:order_items,id'],
            'qty' => ['required', 'numeric', 'min:0.001'],
            'basis' => ['nullable', 'string', 'in:to_customer,to_work'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $order = Order::query()->withoutGlobalScopes()
            ->where('tenant_id', $request->user()->tenant_id)
            ->whereKey($validated['order_id'])
            ->firstOrFail();

        $this->authorize('update', $order);

        $issuance = $this->issuances->issue(
            (int) $request->user()->tenant_id,
            (int) $validated['order_id'],
            (int) $validated['order_item_id'],
            (float) $validated['qty'],
            (int) $request->user()->id,
            $validated['basis'] ?? Issuance::BASIS_TO_CUSTOMER,
            $validated['note'] ?? null,
        );

        return response()->json(['data' => $issuance], 201);
    }
}
