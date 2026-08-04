<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

use Autometria\Exceptions\Domain\CircularBomException;
use Autometria\Models\ProductService;
use Autometria\Models\Stock;
use Autometria\Models\Tenant;
use Autometria\Services\NestedBomService;
use Autometria\Services\ProductionService;
use Autometria\Services\StockBatchService;
use Tests\Support\AcceptanceFixture;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

beforeEach(function (): void {
    $this->withoutMiddleware([
        \Autometria\Http\Middleware\EnsurePermission::class,
        \Autometria\Http\Middleware\EnforceLocationAccess::class,
    ]);
    config(['cache.default' => 'array']);

    $this->fx = AcceptanceFixture::make('nbom-'.uniqid());
    set_current_tenant_id($this->fx->tenant->id);
    actingAs($this->fx->user);

    $this->production = app(ProductionService::class);
    $this->nested = app(NestedBomService::class);
    $this->batches = app(StockBatchService::class);
});

function nbomProduct(object $fx, string $name, bool $withStock = false, float $cost = 10.0, float $qty = 100.0): ProductService
{
    $p = ProductService::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $fx->tenant->id,
        'type' => 'product',
        'name' => $name,
        'article' => 'NB-'.uniqid(),
        'base_price' => $cost * 2,
        'is_active' => true,
    ]);

    if ($withStock) {
        app(StockBatchService::class)->ingress(
            $fx->tenant->id,
            $fx->warehouse->id,
            $p->id,
            $qty,
            $cost,
            'NB-'.$p->id,
        );
    }

    return $p;
}

it('expands nested BOM to aggregated leaf ingredients', function (): void {
    $rubber = nbomProduct($this->fx, 'Резина', true, 5.0, 200);
    $cord = nbomProduct($this->fx, 'Корд', true, 8.0, 200);
    $semi = nbomProduct($this->fx, 'Каркас');
    $finished = nbomProduct($this->fx, 'Шина');

    // Semi: 1 каркас = 2 резины + 1 корд
    $semiRecipe = $this->production->upsertRecipe(
        $this->fx->tenant->id,
        $semi->id,
        1.0,
        null,
        [
            ['ingredient_id' => $rubber->id, 'quantity' => 2.0, 'waste_percentage' => 0],
            ['ingredient_id' => $cord->id, 'quantity' => 1.0, 'waste_percentage' => 0],
        ],
    );

    // Finished: 1 шина = 2 каркаса
    $finRecipe = $this->production->upsertRecipe(
        $this->fx->tenant->id,
        $finished->id,
        1.0,
        null,
        [
            ['ingredient_id' => $semi->id, 'quantity' => 2.0, 'waste_percentage' => 0],
        ],
    );

    $preview = $this->nested->preview($this->fx->tenant->id, (int) $finRecipe->id, 3.0);
    $byId = collect($preview['leaves'])->keyBy('product_id');

    // 3 tires × 2 frames × 2 rubber = 12; 3 × 2 × 1 cord = 6
    expect((float) $byId[$rubber->id]['qty'])->toBe(12.0);
    expect((float) $byId[$cord->id]['qty'])->toBe(6.0);
    expect($byId->has($semi->id))->toBeFalse();
    expect($semi->fresh()->is_semi_finished)->toBeTrue();
    expect($semiRecipe->id)->toBeInt();
});

it('produceBatch writes off leaf materials for nested recipe', function (): void {
    $rubber = nbomProduct($this->fx, 'Резина-P', true, 10.0, 100);
    $semi = nbomProduct($this->fx, 'ПФ-P');
    $finished = nbomProduct($this->fx, 'ГП-P');

    $this->production->upsertRecipe(
        $this->fx->tenant->id,
        $semi->id,
        1.0,
        null,
        [['ingredient_id' => $rubber->id, 'quantity' => 4.0, 'waste_percentage' => 0]],
    );

    $finRecipe = $this->production->upsertRecipe(
        $this->fx->tenant->id,
        $finished->id,
        1.0,
        null,
        [['ingredient_id' => $semi->id, 'quantity' => 1.0, 'waste_percentage' => 0]],
    );

    $result = $this->production->produceBatch(
        (int) $finRecipe->id,
        2.0,
        $this->fx->warehouse->id,
        $this->fx->user->id,
    );

    // 2 GP × 1 SF × 4 rubber = 8 @ 10 = 80
    expect($result['total_cost'])->toBe(80.0);
    expect((float) Stock::query()->withoutGlobalScopes()->where('product_id', $rubber->id)->value('available'))
        ->toBe(92.0);
    expect((float) Stock::query()->withoutGlobalScopes()->where('product_id', $finished->id)->value('available'))
        ->toBe(2.0);
});

it('detects circular BOM references', function (): void {
    $a = nbomProduct($this->fx, 'A-cycle');
    $b = nbomProduct($this->fx, 'B-cycle');

    $this->production->upsertRecipe(
        $this->fx->tenant->id,
        $a->id,
        1.0,
        null,
        [['ingredient_id' => $b->id, 'quantity' => 1.0]],
    );
    $recipeB = $this->production->upsertRecipe(
        $this->fx->tenant->id,
        $b->id,
        1.0,
        null,
        [['ingredient_id' => $a->id, 'quantity' => 1.0]],
    );

    expect(fn () => $this->nested->preview($this->fx->tenant->id, (int) $recipeB->id, 1.0))
        ->toThrow(CircularBomException::class);
});

it('rejects BOM deeper than tenants.max_bom_depth', function (): void {
    Tenant::query()->whereKey($this->fx->tenant->id)->update(['max_bom_depth' => 1]);

    $leaf = nbomProduct($this->fx, 'Leaf-D', true, 1.0, 50);
    $l1 = nbomProduct($this->fx, 'L1');
    $l2 = nbomProduct($this->fx, 'L2');
    $l3 = nbomProduct($this->fx, 'L3');

    $this->production->upsertRecipe(
        $this->fx->tenant->id,
        $l1->id,
        1.0,
        null,
        [['ingredient_id' => $leaf->id, 'quantity' => 1.0]],
    );
    $this->production->upsertRecipe(
        $this->fx->tenant->id,
        $l2->id,
        1.0,
        null,
        [['ingredient_id' => $l1->id, 'quantity' => 1.0]],
    );
    $r3 = $this->production->upsertRecipe(
        $this->fx->tenant->id,
        $l3->id,
        1.0,
        null,
        [['ingredient_id' => $l2->id, 'quantity' => 1.0]],
    );

    expect(fn () => $this->nested->preview($this->fx->tenant->id, (int) $r3->id, 1.0))
        ->toThrow(CircularBomException::class);

    postJson('/api/v1/production/nested-preview', [
        'recipe_id' => $r3->id,
        'qty' => 1,
    ])->assertStatus(422)->assertJsonPath('code', 'CircularBomException');
});
