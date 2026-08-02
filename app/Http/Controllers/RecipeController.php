<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Http\Controllers;

use Autometria\Models\Recipe;
use Autometria\Services\ProductionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RecipeController extends Controller
{
    public function __construct(
        private readonly ProductionService $production,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);

        $rows = Recipe::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->with(['items.ingredient', 'product'])
            ->orderByDesc('id')
            ->get()
            ->map(fn (Recipe $r) => $this->serialize($r, $request));

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $data = $this->validated($request);

        $recipe = $this->production->upsertRecipe(
            $tenantId,
            (int) $data['product_id'],
            (float) $data['yield_quantity'],
            $data['instructions'] ?? null,
            $data['items'],
        );

        return response()->json(['data' => $this->serialize($recipe, $request)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $data = $this->validated($request);

        $recipe = $this->production->upsertRecipe(
            $tenantId,
            (int) $data['product_id'],
            (float) $data['yield_quantity'],
            $data['instructions'] ?? null,
            $data['items'],
            $id,
        );

        return response()->json(['data' => $this->serialize($recipe, $request)]);
    }

    public function costBreakdown(Request $request, int $id): JsonResponse
    {
        $tenantId = $this->tenantId($request);

        $recipe = Recipe::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('product_id', $id)
            ->with(['items.ingredient'])
            ->first();

        if ($recipe === null) {
            $recipe = Recipe::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereKey($id)
                ->with(['items.ingredient'])
                ->firstOrFail();
        }

        $warehouseId = $request->integer('warehouse_id') ?: null;
        $breakdown = $this->production->calculateRecipeCost($recipe, $warehouseId ?: null);

        return response()->json([
            'data' => array_merge([
                'recipe_id' => $recipe->id,
                'product_id' => $recipe->product_id,
            ], $breakdown),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'product_id' => ['required', 'integer'],
            'yield_quantity' => ['required', 'numeric', 'min:0.001'],
            'instructions' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.ingredient_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.waste_percentage' => ['nullable', 'numeric', 'min:0', 'max:99.999'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(Recipe $recipe, Request $request): array
    {
        $warehouseId = $request->integer('warehouse_id') ?: null;
        $cost = $this->production->calculateRecipeCost($recipe, $warehouseId ?: null);

        return [
            'id' => $recipe->id,
            'product_id' => $recipe->product_id,
            'product_name' => $recipe->product?->name,
            'yield_quantity' => round((float) $recipe->yield_quantity, 3),
            'instructions' => $recipe->instructions,
            'unit_cost' => $cost['unit_cost'],
            'total_cost' => $cost['total_cost'],
            'items' => $recipe->items->map(fn ($i) => [
                'id' => $i->id,
                'ingredient_id' => $i->ingredient_id,
                'ingredient_name' => $i->ingredient?->name,
                'quantity' => round((float) $i->quantity, 3),
                'waste_percentage' => round((float) $i->waste_percentage, 3),
                'net_quantity' => round((float) $i->net_quantity, 3),
            ])->values(),
        ];
    }

    private function tenantId(Request $request): int
    {
        $id = (int) ($request->user()?->tenant_id ?? tenant_id() ?? 0);
        abort_unless($id > 0, 422, 'Tenant context required');

        return $id;
    }
}
