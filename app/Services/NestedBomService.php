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
use Autometria\Services\Traits\BcMathDecimal;
use InvalidArgumentException;

/**
 * Nested BOM — рекурсивное развёртывание спецификации до leaf-ингредиентов.
 */
final class NestedBomService
{
    use BcMathDecimal;

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
    public function preview(int $tenantId, int $recipeId, float|int|string $qty): array
    {
        if ($this->bcComp($qty, '0') <= 0) {
            throw new InvalidArgumentException('Preview qty must be positive');
        }

        $index = $this->loadRecipeIndex($tenantId);
        $recipe = $index['by_id'][$recipeId] ?? null;
        if ($recipe === null) {
            throw (new \Illuminate\Database\Eloquent\ModelNotFoundException)->setModel(Recipe::class, [$recipeId]);
        }

        $maxDepth = $this->maxBomDepth($tenantId);
        $leaves = [];
        $tree = $this->expandRecipeNode(
            $tenantId,
            $recipe,
            $this->bcRound($qty, 3),
            $maxDepth,
            0,
            [],
            $leaves,
            $index['by_product'],
        );

        $aggregated = [];
        foreach ($leaves as $row) {
            $pid = (int) $row['product_id'];
            if (! isset($aggregated[$pid])) {
                $aggregated[$pid] = [
                    'product_id' => $pid,
                    'name' => $row['name'],
                    'qty' => '0',
                ];
            }
            $aggregated[$pid]['qty'] = $this->bcAdd($aggregated[$pid]['qty'], $row['qty']);
        }

        $leafList = [];
        foreach ($aggregated as $row) {
            $leafList[] = [
                'product_id' => $row['product_id'],
                'name' => $row['name'],
                'qty' => $this->bcToFloat($this->bcRound($row['qty'], 3)),
            ];
        }

        return [
            'recipe_id' => (int) $recipe->id,
            'product_id' => (int) $recipe->product_id,
            'product_name' => $recipe->product?->name,
            'qty' => $this->bcToFloat($this->bcRound($qty, 3)),
            'yield_quantity' => $this->bcToFloat($this->bcRound($recipe->yield_quantity ?? '0', 3)),
            'max_bom_depth' => $maxDepth,
            'tree' => $tree,
            'leaves' => $leafList,
        ];
    }

    /**
     * Суммарная потребность leaf-ингредиентов (без дерева) для FIFO write-off.
     *
     * @return list<array{product_id: int, qty: float}>
     */
    public function expandToLeaves(int $tenantId, int $recipeId, float|int|string $qty): array
    {
        $preview = $this->preview($tenantId, $recipeId, $qty);

        return array_map(
            static fn (array $row): array => [
                'product_id' => (int) $row['product_id'],
                'qty' => (float) $row['qty'],
            ],
            $preview['leaves'],
        );
    }

    /**
     * One batch load of all tenant recipes → O(1) lookups during expand.
     *
     * @return array{by_id: array<int, Recipe>, by_product: array<int, Recipe>}
     */
    private function loadRecipeIndex(int $tenantId): array
    {
        $recipes = Recipe::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->with(['items.ingredient', 'product'])
            ->get();

        $byId = [];
        $byProduct = [];
        foreach ($recipes as $recipe) {
            $byId[(int) $recipe->id] = $recipe;
            $byProduct[(int) $recipe->product_id] = $recipe;
        }

        return ['by_id' => $byId, 'by_product' => $byProduct];
    }

    private function maxBomDepth(int $tenantId): int
    {
        $depth = Tenant::query()->whereKey($tenantId)->value('max_bom_depth');

        return max(1, (int) ($depth ?? 5));
    }

    /**
     * @param  list<int>  $path
     * @param  list<array{product_id: int, name: ?string, qty: string}>  $leaves
     * @param  array<int, Recipe>  $byProduct
     * @return array<string, mixed>
     */
    private function expandRecipeNode(
        int $tenantId,
        Recipe $recipe,
        string $outputQty,
        int $maxDepth,
        int $depth,
        array $path,
        array &$leaves,
        array $byProduct,
    ): array {
        $productId = (int) $recipe->product_id;
        if (in_array($productId, $path, true)) {
            throw CircularBomException::cycle($productId);
        }
        if ($depth > $maxDepth) {
            throw CircularBomException::depthExceeded($maxDepth);
        }

        $yield = $this->bcMax($recipe->yield_quantity ?? '0', '0.001');
        $scale = $this->bcDiv($outputQty, $yield, 6);
        $nextPath = [...$path, $productId];
        $children = [];

        foreach ($recipe->items as $item) {
            $ingredientId = (int) $item->ingredient_id;
            $waste = $this->bcMin($this->bcMax($item->waste_percentage ?? '0', '0'), '99.999');
            $base = $this->bcNormalize($item->net_quantity ?? '0');
            if ($this->bcComp($base, '0') <= 0) {
                $base = $this->bcNormalize($item->quantity ?? '0');
            }
            $need = $this->bcRound($this->bcMul($base, $scale, 6), 3);
            if ($this->bcComp($need, '0') <= 0) {
                continue;
            }

            $name = $item->ingredient?->name;
            $childRecipe = $byProduct[$ingredientId] ?? null;

            if ($childRecipe === null) {
                $leaves[] = [
                    'product_id' => $ingredientId,
                    'name' => $name,
                    'qty' => $need,
                ];
                $children[] = [
                    'product_id' => $ingredientId,
                    'name' => $name,
                    'qty' => $this->bcToFloat($need),
                    'waste_percentage' => $this->bcToFloat($this->bcRound($waste, 3)),
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
                'qty' => $this->bcToFloat($need),
                'waste_percentage' => $this->bcToFloat($this->bcRound($waste, 3)),
                'is_leaf' => false,
                'is_semi_finished' => true,
                'recipe_id' => (int) $childRecipe->id,
                'yield_quantity' => $this->bcToFloat($this->bcRound($childRecipe->yield_quantity ?? '0', 3)),
                'depth' => $depth + 1,
                'children' => $this->expandRecipeNode(
                    $tenantId,
                    $childRecipe,
                    $need,
                    $maxDepth,
                    $depth + 1,
                    $nextPath,
                    $leaves,
                    $byProduct,
                )['children'] ?? [],
            ];
        }

        return [
            'product_id' => $productId,
            'name' => $recipe->product?->name,
            'qty' => $this->bcToFloat($this->bcRound($outputQty, 3)),
            'recipe_id' => (int) $recipe->id,
            'yield_quantity' => $this->bcToFloat($this->bcRound($yield, 3)),
            'is_leaf' => false,
            'is_semi_finished' => $depth > 0,
            'depth' => $depth,
            'children' => $children,
        ];
    }
}
