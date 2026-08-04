<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Services;

use Autometria\Models\ModifierOption;
use Autometria\Models\OrderItem;
use Autometria\Models\Price;
use Autometria\Models\ProductService;
use Autometria\Models\ProductionOrder;
use Autometria\Models\Recipe;
use Autometria\Models\RecipeItem;
use Autometria\Models\StockBatch;
use Autometria\Support\AuditLog;
use Autometria\Services\Traits\BcMathDecimal;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Production & BOM — ТТК, себестоимость FIFO, автосписание сырья, акты производства.
 */
final class ProductionService
{
    use BcMathDecimal;

    public function __construct(
        private readonly StockBatchService $batches,
        private readonly NestedBomService $nestedBom,
    ) {}

    /**
     * Динамическая себестоимость блюда: сумма FIFO-стоимости брутто-ингредиентов на yield.
     *
     * @return array{
     *   total_cost: float,
     *   unit_cost: float,
     *   yield_quantity: float,
     *   lines: list<array{ingredient_id: int, name: ?string, gross_qty: float, waste_percentage: float, net_qty: float, unit_cost: float, line_cost: float}>
     * }
     */
    public function calculateRecipeCost(Recipe $recipe, ?int $warehouseId = null): array
    {
        $tenantId = (int) $recipe->tenant_id;
        $yield = max(0.001, (float) $recipe->yield_quantity);
        $recipe->loadMissing(['items.ingredient']);

        $lines = [];
        $total = 0.0;

        foreach ($recipe->items as $item) {
            $gross = (float) $item->quantity;
            $waste = (float) $item->waste_percentage;
            $net = (float) $item->net_quantity;
            if ($net <= 0 && $gross > 0) {
                $net = round($gross * (1 - max(0, min(99.999, $waste)) / 100), 3);
            }

            $lineCost = $this->estimateFifoCost($tenantId, $warehouseId, (int) $item->ingredient_id, $gross);
            $unit = $gross > 0 ? (float) $this->bcRound($this->bcDiv($lineCost, $gross), 4) : 0.0;
            $total = $this->bcAdd($total, $lineCost);

            $lines[] = [
                'ingredient_id' => (int) $item->ingredient_id,
                'name' => $item->ingredient?->name,
                'gross_qty' => round($gross, 3),
                'waste_percentage' => round($waste, 3),
                'net_qty' => round($net, 3),
                'unit_cost' => round($unit, 4),
                'line_cost' => round($lineCost, 2),
            ];
        }

        return [
            'total_cost' => round($total, 2),
            'unit_cost' => round($total / $yield, 4),
            'yield_quantity' => round($yield, 3),
            'lines' => $lines,
        ];
    }

    /**
     * При продаже составного товара — списание ингредиентов (и модификаторов) по FIFO вместо ГП.
     *
     * @return array{composite: bool, written_off: float, cost: float, ingredients: list<array>, has_overdraft: bool}|null
     *         null = не составной (вызывающий должен сделать обычный writeOff ГП)
     */
    public function processCompositeSale(
        OrderItem $item,
        int $warehouseId,
        bool $allowOverdraft = false,
        ?int $createdBy = null,
    ): ?array {
        if ($item->product_id === null || $item->type === 'service') {
            return null;
        }

        $tenantId = (int) $item->tenant_id;
        $recipe = Recipe::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('product_id', (int) $item->product_id)
            ->with('items')
            ->first();

        if ($recipe === null) {
            return null;
        }

        $saleQty = (float) $item->qty;
        $yield = max(0.001, (float) $recipe->yield_quantity);
        $scale = $saleQty / $yield;

        return DB::transaction(function () use (
            $recipe, $item, $tenantId, $warehouseId, $scale, $saleQty, $allowOverdraft, $createdBy
        ): array {
            $totalCost = 0.0;
            $totalWritten = 0.0;
            $hasOverdraft = false;
            $trace = [];

            foreach ($recipe->items as $ri) {
                // Списываем net_quantity (чистый расход с учётом потерь waste),
                // брутто quantity — fallback, если net_quantity не задан.
                $base = (float) ($ri->net_quantity ?? $ri->quantity);
                $need = round($base * $scale, 3);
                if ($need <= 0) {
                    continue;
                }
                $result = $this->batches->writeOff(
                    $tenantId,
                    $warehouseId,
                    (int) $ri->ingredient_id,
                    $need,
                    $createdBy,
                    (int) $item->order_id,
                    (int) $item->id,
                    $allowOverdraft,
                );
                $totalCost = $this->bcAdd($totalCost, (float) $result['cost']);
                $totalWritten = $this->bcAdd($totalWritten, (float) $result['written_off']);
                $hasOverdraft = $hasOverdraft || (($result['has_overdraft'] ?? false) === true);
                $trace[] = [
                    'ingredient_id' => (int) $ri->ingredient_id,
                    'qty' => $need,
                    'cost' => (float) $result['cost'],
                ];
            }

            // Modifier ingredient write-offs from snapshot.modifiers / modifier_option_ids
            foreach ($this->modifierWriteOffsFromSnapshot($item, $saleQty) as $mod) {
                $result = $this->batches->writeOff(
                    $tenantId,
                    $warehouseId,
                    $mod['ingredient_id'],
                    $mod['qty'],
                    $createdBy,
                    (int) $item->order_id,
                    (int) $item->id,
                    $allowOverdraft,
                );
                $totalCost = $this->bcAdd($totalCost, (float) $result['cost']);
                $totalWritten = $this->bcAdd($totalWritten, (float) $result['written_off']);
                $hasOverdraft = $hasOverdraft || (($result['has_overdraft'] ?? false) === true);
                $trace[] = [
                    'ingredient_id' => $mod['ingredient_id'],
                    'qty' => $mod['qty'],
                    'cost' => (float) $result['cost'],
                    'modifier' => true,
                ];
            }

            AuditLog::write(
                $tenantId,
                $createdBy ?? auth()->id(),
                'production.composite_sale',
                OrderItem::class,
                (int) $item->id,
                [],
                ['recipe_id' => $recipe->id, 'sale_qty' => $saleQty, 'cost' => round($totalCost, 2)],
            );

            return [
                'composite' => true,
                'written_off' => round($totalWritten, 3),
                'cost' => round($totalCost, 2),
                'ingredients' => $trace,
                'has_overdraft' => $hasOverdraft,
            ];
        });
    }

    /**
     * Акт производства: nested BOM → списание leaf-сырья FIFO + приход ГП/полуфабриката.
     *
     * @return array{batch_id: int, qty: float, unit_cost: float, total_cost: float, ingredients: list<array>}
     */
    public function produceBatch(int $recipeId, float $qty, int $warehouseId, ?int $createdBy = null): array
    {
        if ($qty <= 0) {
            throw new InvalidArgumentException('Production qty must be positive');
        }

        $tenantId = (int) (tenant_id() ?? 0);
        abort_unless($tenantId > 0, 422, 'Tenant context required');

        $recipe = Recipe::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereKey($recipeId)
            ->with('items')
            ->firstOrFail();

        $leaves = $this->nestedBom->expandToLeaves($tenantId, $recipeId, $qty);

        return DB::transaction(function () use (
            $recipe, $tenantId, $warehouseId, $qty, $leaves, $createdBy
        ): array {
            $totalCost = 0.0;
            $trace = [];

            foreach ($leaves as $leaf) {
                $need = round((float) $leaf['qty'], 3);
                if ($need <= 0) {
                    continue;
                }
                $result = $this->batches->writeOff(
                    $tenantId,
                    $warehouseId,
                    (int) $leaf['product_id'],
                    $need,
                    $createdBy,
                );
                $totalCost = $this->bcAdd($totalCost, (float) $result['cost']);
                $trace[] = [
                    'ingredient_id' => (int) $leaf['product_id'],
                    'qty' => $need,
                    'cost' => (float) $result['cost'],
                    'leaf' => true,
                ];
            }

            $unitCost = (float) $this->bcRound($this->bcDiv($totalCost, $qty), 4);
            $batch = $this->batches->ingress(
                $tenantId,
                $warehouseId,
                (int) $recipe->product_id,
                $qty,
                round($unitCost, 2),
                'PROD-'.$recipe->id.'-'.now()->format('YmdHis'),
                $createdBy,
            );

            $this->markSemiFinished($tenantId, (int) $recipe->product_id);

            $order = ProductionOrder::query()->withoutGlobalScopes()->forceCreate([
                'tenant_id' => $tenantId,
                'recipe_id' => $recipe->id,
                'product_id' => $recipe->product_id,
                'warehouse_id' => $warehouseId,
                'qty' => round($qty, 3),
                'unit_cost' => $unitCost,
                'total_cost' => round($totalCost, 2),
                'batch_id' => $batch->id,
                'created_by' => $createdBy,
                'status' => 'COMPLETED',
                'ingredients' => $trace,
            ]);

            AuditLog::write(
                $tenantId,
                $createdBy ?? auth()->id(),
                'production.produce',
                ProductionOrder::class,
                (int) $order->id,
                [],
                [
                    'recipe_id' => $recipe->id,
                    'qty' => $qty,
                    'unit_cost' => $unitCost,
                    'total_cost' => round($totalCost, 2),
                    'batch_id' => $batch->id,
                    'ingredients' => $trace,
                    'nested' => true,
                ],
            );

            return [
                'id' => (int) $order->id,
                'batch_id' => (int) $batch->id,
                'qty' => round($qty, 3),
                'unit_cost' => $unitCost,
                'total_cost' => round($totalCost, 2),
                'ingredients' => $trace,
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function nestedPreview(int $tenantId, int $recipeId, float $qty): array
    {
        return $this->nestedBom->preview($tenantId, $recipeId, $qty);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listProductionOrders(int $tenantId, int $limit = 50): array
    {
        return ProductionOrder::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->with(['product', 'recipe', 'warehouse'])
            ->orderByDesc('id')
            ->limit(max(1, min(200, $limit)))
            ->get()
            ->map(fn (ProductionOrder $o) => [
                'id' => $o->id,
                'recipe_id' => $o->recipe_id,
                'product_id' => $o->product_id,
                'product_name' => $o->product?->name,
                'warehouse_id' => $o->warehouse_id,
                'warehouse_name' => $o->warehouse?->name,
                'qty' => round((float) $o->qty, 3),
                'unit_cost' => round((float) $o->unit_cost, 4),
                'total_cost' => round((float) $o->total_cost, 2),
                'batch_id' => $o->batch_id,
                'status' => $o->status,
                'created_at' => optional($o->created_at)?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * @param  list<array{ingredient_id: int, quantity: float, waste_percentage?: float}>  $items
     */
    public function upsertRecipe(
        int $tenantId,
        int $productId,
        float $yieldQuantity,
        ?string $instructions,
        array $items,
        ?int $recipeId = null,
    ): Recipe {
        return DB::transaction(function () use ($tenantId, $productId, $yieldQuantity, $instructions, $items, $recipeId): Recipe {
            if ($recipeId !== null) {
                $recipe = Recipe::query()->withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->whereKey($recipeId)
                    ->firstOrFail();
                $recipe->forceFill([
                    'product_id' => $productId,
                    'yield_quantity' => $yieldQuantity,
                    'instructions' => $instructions,
                ])->save();
                RecipeItem::query()->withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('recipe_id', $recipe->id)
                    ->delete();
            } else {
                $existing = Recipe::query()->withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('product_id', $productId)
                    ->first();
                if ($existing) {
                    $recipe = $existing;
                    $recipe->forceFill([
                        'yield_quantity' => $yieldQuantity,
                        'instructions' => $instructions,
                    ])->save();
                    RecipeItem::query()->withoutGlobalScopes()
                        ->where('tenant_id', $tenantId)
                        ->where('recipe_id', $recipe->id)
                        ->delete();
                } else {
                    $recipe = Recipe::query()->withoutGlobalScopes()->forceCreate([
                        'tenant_id' => $tenantId,
                        'product_id' => $productId,
                        'yield_quantity' => $yieldQuantity,
                        'instructions' => $instructions,
                    ]);
                }
            }

            foreach ($items as $row) {
                $gross = (float) $row['quantity'];
                $waste = (float) ($row['waste_percentage'] ?? 0);
                RecipeItem::query()->withoutGlobalScopes()->forceCreate([
                    'tenant_id' => $tenantId,
                    'recipe_id' => $recipe->id,
                    'ingredient_id' => (int) $row['ingredient_id'],
                    'quantity' => $gross,
                    'waste_percentage' => $waste,
                    'net_quantity' => round($gross * (1 - max(0, min(99.999, $waste)) / 100), 3),
                ]);
            }

            $this->markSemiFinished($tenantId, $productId);

            foreach ($items as $row) {
                $ingredientId = (int) $row['ingredient_id'];
                $hasChildRecipe = Recipe::query()->withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('product_id', $ingredientId)
                    ->exists();
                if ($hasChildRecipe) {
                    $this->markSemiFinished($tenantId, $ingredientId);
                }
            }

            return $recipe->fresh(['items.ingredient', 'product']);
        });
    }

    private function markSemiFinished(int $tenantId, int $productId): void
    {
        ProductService::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereKey($productId)
            ->update(['is_semi_finished' => true]);
    }

    /**
     * FIFO cost estimate for qty (read-only walk). Falls back to prices.cost_price.
     */
    private function estimateFifoCost(int $tenantId, ?int $warehouseId, int $productId, float $qty): float
    {
        if ($qty <= 0) {
            return 0.0;
        }

        $query = StockBatch::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('product_id', $productId)
            ->where('remaining_qty', '>', 0)
            ->where(function ($q): void {
                $q->whereNull('is_overdraft')->orWhere('is_overdraft', false);
            })
            ->orderBy('received_at')
            ->orderBy('id');

        if ($warehouseId !== null) {
            $query->where('warehouse_id', $warehouseId);
        }

        $batches = $query->get(['remaining_qty', 'cost_price']);
        $need = $qty;
        $cost = 0.0;

        foreach ($batches as $batch) {
            if ($need <= 0) {
                break;
            }
            $take = min((float) $batch->remaining_qty, $need);
            $cost += $take * (float) $batch->cost_price;
            $need -= $take;
        }

        if ($need > 0.0001) {
            $fallback = (float) (Price::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('product_id', $productId)
                ->where('type', 'retail')
                ->orderByDesc('id')
                ->value('cost_price') ?? 0);
            $cost += $need * $fallback;
        }

        return round($cost, 2);
    }

    /**
     * @return list<array{ingredient_id: int, qty: float}>
     */
    private function modifierWriteOffsFromSnapshot(OrderItem $item, float $saleQty): array
    {
        $snapshot = is_array($item->snapshot) ? $item->snapshot : [];
        $optionIds = $snapshot['modifier_option_ids'] ?? $snapshot['modifiers'] ?? [];
        if (! is_array($optionIds) || $optionIds === []) {
            return [];
        }

        $ids = array_values(array_filter(array_map('intval', $optionIds)));
        if ($ids === []) {
            return [];
        }

        $options = ModifierOption::query()->withoutGlobalScopes()
            ->where('tenant_id', (int) $item->tenant_id)
            ->whereIn('id', $ids)
            ->whereNotNull('ingredient_id')
            ->where('ingredient_qty', '>', 0)
            ->get();

        $out = [];
        foreach ($options as $opt) {
            $out[] = [
                'ingredient_id' => (int) $opt->ingredient_id,
                'qty' => round((float) $opt->ingredient_qty * $saleQty, 3),
            ];
        }

        return $out;
    }
}
