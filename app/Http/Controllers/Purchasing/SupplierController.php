<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Http\Controllers\Purchasing;

use Autometria\Http\Controllers\Controller;
use Autometria\Services\Purchasing\SupplierOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class SupplierController extends Controller
{
    public function __construct(
        private readonly SupplierOrderService $purchasing,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $activeOnly = filter_var($request->query('active_only', false), FILTER_VALIDATE_BOOL);

        $rows = $this->purchasing->listSuppliers($tenantId, $activeOnly)->map(fn ($s) => [
            'id' => $s->id,
            'name' => $s->name,
            'inn' => $s->inn,
            'contact_person' => $s->contact_person,
            'phone' => $s->phone,
            'email' => $s->email,
            'address' => $s->address,
            'is_active' => (bool) $s->is_active,
        ])->values();

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'inn' => ['nullable', 'string', 'max:32'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:64'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        try {
            $supplier = $this->purchasing->createSupplier($tenantId, $data);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => [
                'id' => $supplier->id,
                'name' => $supplier->name,
                'inn' => $supplier->inn,
                'is_active' => (bool) $supplier->is_active,
            ],
        ], 201);
    }

    private function tenantId(Request $request): int
    {
        $id = (int) ($request->user()?->tenant_id ?? tenant_id() ?? 0);
        abort_unless($id > 0, 422, 'Tenant context required');

        return $id;
    }
}
