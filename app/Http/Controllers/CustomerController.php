<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Http\Controllers;

use Autometria\Enums\LoyaltyTierEnum;
use Autometria\Models\Customer;
use Autometria\Models\LoyaltyTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CustomerController extends Controller
{
    /**
     * POS / admin search: phone / card / name (+ admin filters).
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $q = trim((string) $request->query('q', $request->query('search', '')));
        $phone = trim((string) $request->query('phone', ''));
        $card = trim((string) $request->query('card', $request->query('discount_card_number', '')));
        $tier = trim((string) $request->query('tier', ''));

        $query = Customer::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('id')
            ->limit(50);

        // Frontend CustomerSelector sends q+phone+card together — OR-search.
        $query->where(function ($outer) use ($q, $phone, $card) {
            $has = false;
            if ($phone !== '') {
                $has = true;
                $digits = preg_replace('/\D+/', '', $phone) ?: $phone;
                $outer->orWhere(function ($b) use ($phone, $digits) {
                    $b->where('phone', $phone)
                        ->orWhere('phone', 'like', '%'.$digits.'%');
                });
            }
            if ($card !== '') {
                $has = true;
                $outer->orWhere('discount_card_number', $card)
                    ->orWhere('discount_card_number', 'ilike', '%'.$card.'%');
            }
            if ($q !== '') {
                $has = true;
                $like = '%'.$q.'%';
                $outer->orWhere(function ($b) use ($like) {
                    $b->where('name', 'ilike', $like)
                        ->orWhere('legal_name', 'ilike', $like)
                        ->orWhere('phone', 'ilike', $like)
                        ->orWhere('discount_card_number', 'ilike', $like)
                        ->orWhere('email', 'ilike', $like);
                });
            }
            if (! $has) {
                $outer->whereRaw('1=1');
            }
        });

        if ($tier !== '') {
            $query->where('tier', strtoupper($tier));
        }

        $rows = $query->get()->map(fn (Customer $c) => $this->serialize($c))->values();

        return response()->json(['data' => $rows]);
    }

    /**
     * Quick POS registration.
     */
    public function store(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $userId = (int) $request->user()->id;

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => [
                'required',
                'string',
                'max:32',
                Rule::unique('customers', 'phone')->where(fn ($q) => $q->where('tenant_id', $tenantId)),
            ],
            'email' => ['nullable', 'email', 'max:255'],
            'discount_card_number' => [
                'nullable',
                'string',
                'max:64',
                Rule::unique('customers', 'discount_card_number')->where(fn ($q) => $q->where('tenant_id', $tenantId)),
            ],
            'type' => ['nullable', 'string', Rule::in([Customer::TYPE_INDIVIDUAL, Customer::TYPE_LEGAL])],
        ]);

        $customer = Customer::query()->withoutGlobalScopes()->forceCreate([
            'tenant_id' => $tenantId,
            'type' => $data['type'] ?? Customer::TYPE_INDIVIDUAL,
            'name' => $data['name'],
            'legal_name' => $data['name'],
            'phone' => $data['phone'],
            'email' => $data['email'] ?? null,
            'discount_card_number' => $data['discount_card_number'] ?? null,
            'bonus_balance' => 0,
            'total_spent' => 0,
            'tier' => LoyaltyTierEnum::BRONZE->value,
            'created_by' => $userId,
        ]);

        return response()->json(['data' => $this->serialize($customer)], 201);
    }

    /**
     * GET /api/v1/customers/{id}/transactions
     */
    public function transactions(Request $request, int $id): JsonResponse
    {
        $tenantId = $this->tenantId($request);

        Customer::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereKey($id)
            ->firstOrFail();

        $rows = LoyaltyTransaction::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('customer_id', $id)
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

    private function serialize(Customer $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name ?? $c->legal_name,
            'phone' => $c->phone,
            'email' => $c->email,
            'discount_card_number' => $c->discount_card_number,
            'bonus_balance' => round((float) ($c->bonus_balance ?? 0), 2),
            'total_spent' => round((float) ($c->total_spent ?? 0), 2),
            'tier' => $c->tier ?? LoyaltyTierEnum::BRONZE->value,
        ];
    }

    private function tenantId(Request $request): int
    {
        $id = (int) ($request->user()?->tenant_id ?? tenant_id() ?? 0);
        abort_unless($id > 0, 422, 'Tenant context required');

        return $id;
    }
}
