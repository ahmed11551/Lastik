<?php

declare(strict_types=1);

namespace Autometria\Http\Controllers\Portal;

use Autometria\Http\Controllers\Controller;
use Autometria\Models\Customer;
use Autometria\Services\Portal\CustomerPortalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AuthController extends Controller
{
    public function __construct(private readonly CustomerPortalService $portal) {}

    public function requestToken(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tenant_id' => ['nullable', 'integer', 'exists:tenants,id'],
            'customer_id' => ['nullable', 'integer'],
            'phone' => ['nullable', 'string', 'max:255', 'required_without_all:email,customer_id'],
            'email' => ['nullable', 'email', 'max:255', 'required_without_all:phone,customer_id'],
        ]);

        $tenantId = (int) ($data['tenant_id'] ?? $request->header('X-Tenant-ID') ?? 0);
        $query = Customer::query()->withoutGlobalScopes();

        if ($tenantId > 0) {
            $query->where('tenant_id', $tenantId);
        }

        if (! empty($data['customer_id'])) {
            $query->whereKey($data['customer_id']);
        } elseif (! empty($data['phone'])) {
            $query->where('phone', $data['phone']);
        } else {
            $query->where('email', $data['email']);
        }

        $customer = $query->firstOrFail();
        $issued = $this->portal->issueToken((int) $customer->tenant_id, (int) $customer->id);

        return response()->json([
            'token' => $issued['plain'],
            'expires_at' => $issued['token']->expires_at,
            'customer' => $customer->only(['id', 'tenant_id', 'name', 'phone', 'email']),
        ]);
    }
}
