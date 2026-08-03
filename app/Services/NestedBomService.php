<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Services;

use Autometria\Exceptions\Domain\CircularBomException;
use Autometria\Models\ProductService;
use Autometria\Models\Recipe;
use Autometria\Models\Tenant;
use InvalidArgumentException;

/**
 * Nested BOM — рекурсивное развёртывание спецификации до leaf-ингредиентов.
 */
final class NestedBomService
{
    /**
     * Дерево потребностей + суммарные leaf-qty для выпуска qty готовой продукции по рецепту.
     *
     * @return array{
     *   recipe_id: int,
     *   product_id: int,
     *   product_name: ?string,
     *   qty: float,
     *   yield_quantity: float,
     *   max_bom_depth: int,
     *   tree: array,
     *   leaves: list<array{product_id: int, name: ?string, qty: float}>
     * }
     */
    public function preview(int $tenantId, int $recipeId, float $qty): array
    {
        if ($qty <= 0) {
            throw new InvalidArgumentException('Preview qty must be positive');
        }

        $recipe = $this->loadRecipe($tenantId, $recipeId);
        $maxDepth = $this->maxBomDepth($tenantId);
        $leaves = [];
        $tree = $this->expandRecipeNode(
            $tenantId,
            $recipe,
            $qty,
            $maxDepth,
            0,
            [],
            $leaves,
        );

        $aggregated = [];
        foreach ($leaves as $row) {
            $pid = (int) $row['product_id'];
            if (! isset($aggregated[$pid])) {
                $aggregated[$pid] = [
                    'product_id' => $pid,
                    'name' => $row['name'],
                    'qty' => 0.0,
                ];
            }
            $aggregated[$pid]['qty'] = round($aggregated[$pid]['qty'] + (float) $row['qty'], 3);
        }

        return [
            'recipe_id' => (int) $recipe->id,
            'product_id' => (int) $recipe->product_id,
            'product_name' => $recipe->product?->name,
            'qty' => round($qty, 3),
            'yield_quantity' => round((float) $recipe->yield_quantity, 3),
            'max_bom_depth' => $maxDepth,
            'tree' => $tree,
            'leaves' => array_values($aggregated),
        ];
    }

    /**
     * Суммарная потребность leaf-ингредиентов (без дерева) для FIFO write-off.
     *
     * @return list<array{product_id: int, qty: float}>
     */
    public function expandToLeaves(int $tenantId, int $recipeId, float $qty): array
    {
        $preview = $this->preview($tenantId, $recipeId, $qty);

        return array_map(
            static fn (array $row): array => [
                'product_id' => (int) $row['product_id'],
                'qty' => round((float) $row['qty'], 3),
            ],
            $preview['leaves'],
        );
    }

    private function maxBomDepth(int $tenantId): int
    {
        $depth = Tenant::query()->whereKey($tenantId)->value('max_bom_depth');

        return max(1, (int) ($depth ?? 5));
    }

    private function loadRecipe(int $tenantId, int $recipeId): Recipe
    {
        return Recipe::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereKey($recipeId)
            ->with(['items.ingredient', 'product'])
            ->firstOrFail();
    }

    private function recipeForProduct(int $tenantId, int $productId): ?Recipe
    {
        return Recipe::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('product_id', $productId)
            ->with(['items.ingredient', 'product'])
            ->first();
    }

    /**
     * @param  list<int>  $path
     * @param  list<array{product_id: int, name: ?string, qty: float}>  $leaves
     * @return array<string, mixed>
     */
    private function expandRecipeNode(
        int $tenantId,
        Recipe $recipe,
        float $outputQty,
        int $maxDepth,
        int $depth,
        array $path,
        array &$leaves,
    ): array {
        $productId = (int) $recipe->product_id;
        if (in_array($productId, $path, true)) {
            throw CircularBomException::cycle($productId);
        }
        if ($depth > $maxDepth) {
            throw CircularBomException::depthExceeded($maxDepth);
        }

        $yield = max(0.001, (float) $recipe->yield_quantity);
        $scale = $outputQty / $yield;
        $nextPath = [...$path, $productId];
        $children = [];

        foreach ($recipe->items as $item) {
            $ingredientId = (int) $item->ingredient_id;
            $waste = max(0.0, min(99.999, (float) ($item->waste_percentage ?? 0)));
            // net_quantity — расход с учётом waste; quantity — брутто-fallback
            $base = (float) ($item->net_quantity ?? 0);
            if ($base <= 0) {
                $base = (float) $item->quantity;
            }
            $need = round($base * $scale, 3);
            if ($need <= 0) {
                continue;
            }

            $name = $item->ingredient?->name;
            $childRecipe = $this->recipeForProduct($tenantId, $ingredientId);

            if ($childRecipe === null) {
                $leaves[] = [
                    'product_id' => $ingredientId,
                    'name' => $name,
                    'qty' => $need,
                ];
                $children[] = [
                    'product_id' => $ingredientId,
                    'name' => $name,
                    'qty' => $need,
                    'waste_percentage' => round($waste, 3),
                    'is_leaf' => true,
                    'is_semi_finished' => false,
                    'depth' => $depth + 1,
                    'children' => [],
                ];
                continue;
            }

            if ($depth + 1 > $maxDepth) {
                throw CircularBomException::depthExceeded($maxDepth);
            }

            ProductService::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereKey($ingredientId)
                ->where('is_semi_finished', false)
                ->update(['is_semi_finished' => true]);

            $children[] = [
                'product_id' => $ingredientId,
                'name' => $name,
                'qty' => $need,
                'waste_percentage' => round($waste, 3),
                'is_leaf' => false,
                'is_semi_finished' => true,
                'recipe_id' => (int) $childRecipe->id,
                'yield_quantity' => round((float) $childRecipe->yield_quantity, 3),
                'depth' => $depth + 1,
                'children' => $this->expandRecipeNode(
                    $tenantId,
                    $childRecipe,
                    $need,
                    $maxDepth,
                    $depth + 1,
                    $nextPath,
                    $leaves,
                )['children'] ?? [],
            ];
        }

        return [
            'product_id' => $productId,
            'name' => $recipe->product?->name,
            'qty' => round($outputQty, 3),
            'recipe_id' => (int) $recipe->id,
            'yield_quantity' => round($yield, 3),
            'is_leaf' => false,
            'is_semi_finished' => $depth > 0,
            'depth' => $depth,
            'children' => $children,
        ];
    }
}
