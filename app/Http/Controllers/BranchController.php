<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Http\Controllers;

use Autometria\Models\Warehouse;
use Autometria\Services\BranchWarehouseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class BranchController extends Controller
{
    public function __construct(
        private readonly BranchWarehouseService $branches,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $activeOnly = filter_var($request->query('active_only', false), FILTER_VALIDATE_BOOL);

        $rows = $this->branches->listBranches($tenantId, $activeOnly)->map(function ($b) {
            return [
                'id' => $b->id,
                'name' => $b->name,
                'code' => $b->code,
                'address' => $b->address,
                'default_warehouse_id' => $b->default_warehouse_id,
                'is_active' => (bool) $b->is_active,
                'warehouses' => $b->warehouses->map(fn ($w) => [
                    'id' => $w->id,
                    'name' => $w->name,
                ])->values()->all(),
            ];
        })->values();

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $data = $request->validate([
            'id' => ['nullable', 'integer'],
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:64'],
            'address' => ['nullable', 'string', 'max:500'],
            'default_warehouse_id' => ['nullable', 'integer'],
            'is_active' => ['nullable', 'boolean'],
            'warehouse_ids' => ['nullable', 'array'],
            'warehouse_ids.*' => ['integer'],
        ]);

        try {
            if (! empty($data['id'])) {
                $branch = $this->branches->updateBranch($tenantId, (int) $data['id'], $data);
            } else {
                $branch = $this->branches->createBranch(
                    $tenantId,
                    (string) $data['name'],
                    (string) $data['code'],
                    $data['address'] ?? null,
                    isset($data['default_warehouse_id']) ? (int) $data['default_warehouse_id'] : null,
                    (bool) ($data['is_active'] ?? true),
                );
            }

            if (! empty($data['warehouse_ids'])) {
                Warehouse::query()->withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->whereIn('id', $data['warehouse_ids'])
                    ->update(['branch_id' => $branch->id]);
            }
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => [
                'id' => $branch->id,
                'name' => $branch->name,
                'code' => $branch->code,
                'address' => $branch->address,
                'default_warehouse_id' => $branch->default_warehouse_id,
                'is_active' => (bool) $branch->is_active,
            ],
        ], empty($data['id']) ? 201 : 200);
    }

    public function listWarehousePrices(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer'],
        ]);

        $rows = \Autometria\Models\WarehouseProductPrice::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('warehouse_id', (int) $data['warehouse_id'])
            ->orderBy('product_id')
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'warehouse_id' => $p->warehouse_id,
                'product_id' => $p->product_id,
                'price' => round((float) $p->price, 2),
            ])
            ->values();

        return response()->json(['data' => $rows]);
    }

    public function upsertWarehousePrices(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer'],
            'prices' => ['required', 'array', 'min:1'],
            'prices.*.product_id' => ['required', 'integer'],
            'prices.*.price' => ['required', 'numeric', 'min:0'],
        ]);

        try {
            $saved = $this->branches->upsertWarehousePrices(
                $tenantId,
                (int) $data['warehouse_id'],
                $data['prices'],
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => collect($saved)->map(fn ($p) => [
                'id' => $p->id,
                'warehouse_id' => $p->warehouse_id,
                'product_id' => $p->product_id,
                'price' => round((float) $p->price, 2),
            ])->values(),
        ]);
    }

    public function consolidatedStock(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $branchId = $request->query('branch_id') ? (int) $request->query('branch_id') : null;
        $productId = $request->query('product_id') ? (int) $request->query('product_id') : null;

        return response()->json([
            'data' => $this->branches->consolidatedStock($tenantId, $branchId, $productId),
        ]);
    }

    public function resolvePrice(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'warehouse_id' => ['required', 'integer'],
        ]);

        $price = $this->branches->resolveProductPrice(
            $tenantId,
            (int) $data['product_id'],
            (int) $data['warehouse_id'],
        );

        return response()->json(['data' => ['price' => $price]]);
    }

    private function tenantId(Request $request): int
    {
        $id = (int) ($request->user()?->tenant_id ?? tenant_id() ?? 0);
        abort_unless($id > 0, 422, 'Tenant context required');

        return $id;
    }
}
