<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Http\Controllers;

use Autometria\Models\Customer;
use Autometria\Models\LoyaltyTransaction;
use Autometria\Services\LoyaltyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class LoyaltyController extends Controller
{
    public function __construct(
        private readonly LoyaltyService $loyalty,
    ) {}

    /**
     * POST /api/v1/loyalty/calculate — preview earn/spend for current cart.
     */
    public function calculate(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);

        $data = $request->validate([
            'customer_id' => ['required', 'integer'],
            'cart_total' => ['required', 'numeric', 'min:0.01'],
            'requested_spend' => ['nullable', 'numeric', 'min:0'],
            'bonus_spend' => ['nullable', 'numeric', 'min:0'],
        ]);

        $customer = Customer::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereKey((int) $data['customer_id'])
            ->firstOrFail();

        $requested = (float) ($data['requested_spend'] ?? $data['bonus_spend'] ?? 0);

        $result = $this->loyalty->calculateForCart(
            $customer,
            (float) $data['cart_total'],
            $requested,
        );

        return response()->json(['data' => $result]);
    }

    /**
     * GET /api/v1/loyalty/transactions?customer_id= — frontend CRM history.
     */
    public function transactions(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);

        $data = $request->validate([
            'customer_id' => ['required', 'integer'],
        ]);

        Customer::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereKey((int) $data['customer_id'])
            ->firstOrFail();

        $rows = LoyaltyTransaction::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('customer_id', (int) $data['customer_id'])
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (LoyaltyTransaction $t) => [
                'id' => $t->id,
                'type' => $t->type,
                'amount' => round((float) $t->amount, 2),
                'balance_after' => round((float) $t->balance_after, 2),
                'receipt_id' => $t->receipt_id,
                'order_id' => $t->order_id,
                'meta' => $t->meta,
                'created_at' => optional($t->created_at)?->toIso8601String(),
            ])
            ->values();

        return response()->json(['data' => $rows]);
    }

    private function tenantId(Request $request): int
    {
        $id = (int) ($request->user()?->tenant_id ?? tenant_id() ?? 0);
        abort_unless($id > 0, 422, 'Tenant context required');

        return $id;
    }
}
