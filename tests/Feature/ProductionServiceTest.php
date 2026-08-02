<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

use Autometria\Models\AuditLog;
use Autometria\Models\CashShift;
use Autometria\Models\OrderItem;
use Autometria\Models\Price;
use Autometria\Models\ProductService;
use Autometria\Models\Stock;
use Autometria\Services\Cash\CashShiftService;
use Autometria\Services\ProductionService;
use Autometria\Services\StockBatchService;
use Tests\Support\AcceptanceFixture;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

beforeEach(function (): void {
    $this->withoutMiddleware([
        \Autometria\Http\Middleware\EnsurePermission::class,
        \Autometria\Http\Middleware\EnforceLocationAccess::class,
    ]);
    config(['cache.default' => 'array']);

    $this->fx = AcceptanceFixture::make('prod-'.uniqid());
    set_current_tenant_id($this->fx->tenant->id);
    actingAs($this->fx->user);

    $this->production = app(ProductionService::class);
    $this->batches = app(StockBatchService::class);
});

function makeIngredient(object $fx, string $name, float $cost, float $qty = 100.0): ProductService
{
    $p = ProductService::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $fx->tenant->id,
        'type' => 'product',
        'name' => $name,
        'article' => 'ING-'.uniqid(),
        'base_price' => $cost * 2,
        'is_active' => true,
    ]);

    Price::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $fx->tenant->id,
        'product_id' => $p->id,
        'type' => 'retail',
        'price' => $cost * 2,
        'amount' => $cost * 2,
        'cost_price' => $cost,
    ]);

    app(StockBatchService::class)->ingress(
        $fx->tenant->id,
        $fx->warehouse->id,
        $p->id,
        $qty,
        $cost,
        'ING-'.$p->id,
    );

    return $p;
}

function makeDish(object $fx, string $name = 'Бургер'): ProductService
{
    $dish = ProductService::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $fx->tenant->id,
        'type' => 'product',
        'name' => $name,
        'article' => 'DISH-'.uniqid(),
        'base_price' => 450,
        'is_active' => true,
    ]);

    Price::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $fx->tenant->id,
        'product_id' => $dish->id,
        'type' => 'retail',
        'price' => 450,
        'amount' => 450,
        'cost_price' => 0,
    ]);

    return $dish;
}

it('calculates recipe cost with waste_percentage on FIFO ingredient cost', function (): void {
    $bun = makeIngredient($this->fx, 'Булка', 20.0, 50);
    $patty = makeIngredient($this->fx, 'Котлета', 80.0, 50);
    $dish = makeDish($this->fx);

    // waste 20% on bun: gross 0.125 → net 0.1; cost uses gross (брутто)
    $recipe = $this->production->upsertRecipe(
        $this->fx->tenant->id,
        $dish->id,
        1.0,
        'Grill medium',
        [
            ['ingredient_id' => $bun->id, 'quantity' => 0.125, 'waste_percentage' => 20],
            ['ingredient_id' => $patty->id, 'quantity' => 0.15, 'waste_percentage' => 0],
        ],
    );

    $cost = $this->production->calculateRecipeCost($recipe, $this->fx->warehouse->id);

    // 0.125*20 + 0.15*80 = 2.5 + 12 = 14.5
    expect($cost['total_cost'])->toBe(14.5);
    expect($cost['unit_cost'])->toBe(14.5);
    expect($cost['lines'][0]['net_qty'])->toBe(0.1);
    expect($cost['lines'][0]['waste_percentage'])->toBe(20.0);
});

it('writes off ingredients on composite POS sale (not finished good)', function (): void {
    $bun = makeIngredient($this->fx, 'Булка', 20.0, 10);
    $patty = makeIngredient($this->fx, 'Котлета', 80.0, 10);
    $dish = makeDish($this->fx);

    $this->production->upsertRecipe(
        $this->fx->tenant->id,
        $dish->id,
        1.0,
        null,
        [
            ['ingredient_id' => $bun->id, 'quantity' => 1.0, 'waste_percentage' => 0],
            ['ingredient_id' => $patty->id, 'quantity' => 1.0, 'waste_percentage' => 10],
        ],
    );

    CashShift::query()->withoutGlobalScopes()
        ->where('tenant_id', $this->fx->tenant->id)
        ->whereNull('closed_at')
        ->update(['closed_at' => now(), 'status' => 'closed']);

    app(CashShiftService::class)->open(
        $this->fx->tenant->id,
        $this->fx->location->id,
        $this->fx->user->id,
        0,
    );

    $bunStockBefore = (float) Stock::query()->withoutGlobalScopes()
        ->where('tenant_id', $this->fx->tenant->id)
        ->where('product_id', $bun->id)
        ->value('available');

    $res = postJson('/api/v1/pos/checkout', [
        'method' => 'cash',
        'amount_tendered' => 1000,
        'items' => [
            [
                'product_id' => $dish->id,
                'qty' => 2,
                'warehouse_id' => $this->fx->warehouse->id,
                'type' => 'product',
            ],
        ],
    ]);
    $res->assertStatus(201);

    $bunStockAfter = (float) Stock::query()->withoutGlobalScopes()
        ->where('tenant_id', $this->fx->tenant->id)
        ->where('product_id', $bun->id)
        ->value('available');

    $pattyStockAfter = (float) Stock::query()->withoutGlobalScopes()
        ->where('tenant_id', $this->fx->tenant->id)
        ->where('product_id', $patty->id)
        ->value('available');

    // 2 portions × 1.0 gross each
    expect($bunStockBefore - $bunStockAfter)->toBe(2.0);
    expect(10.0 - $pattyStockAfter)->toBe(2.0);

    // Finished dish stock should not be required / written off
    $dishStock = Stock::query()->withoutGlobalScopes()
        ->where('tenant_id', $this->fx->tenant->id)
        ->where('product_id', $dish->id)
        ->first();
    expect($dishStock === null || (float) $dishStock->actual === 0.0)->toBeTrue();
});

it('produceBatch writes off raw materials and ingresses finished good at FIFO cost', function (): void {
    $flour = makeIngredient($this->fx, 'Мука', 40.0, 20);
    $semi = makeDish($this->fx, 'Тесто');

    $recipe = $this->production->upsertRecipe(
        $this->fx->tenant->id,
        $semi->id,
        5.0, // yield 5 kg dough per recipe
        null,
        [
            ['ingredient_id' => $flour->id, 'quantity' => 4.0, 'waste_percentage' => 0],
        ],
    );

    // Produce 10 kg → scale = 10/5 = 2 → write off 8 kg flour, ingress 10 kg dough @ cost 40
    $result = $this->production->produceBatch(
        (int) $recipe->id,
        10.0,
        $this->fx->warehouse->id,
        $this->fx->user->id,
    );

    expect($result['qty'])->toBe(10.0);
    expect($result['total_cost'])->toBe(320.0); // 8 * 40
    expect($result['unit_cost'])->toBe(32.0);

    $flourAvail = (float) Stock::query()->withoutGlobalScopes()
        ->where('product_id', $flour->id)->value('available');
    expect($flourAvail)->toBe(12.0); // 20 - 8

    $semiAvail = (float) Stock::query()->withoutGlobalScopes()
        ->where('product_id', $semi->id)->value('available');
    expect($semiAvail)->toBe(10.0);

    expect(
        AuditLog::query()->withoutGlobalScopes()
            ->where('tenant_id', $this->fx->tenant->id)
            ->where('action', 'production.produce')
            ->exists()
    )->toBeTrue();

    getJson('/api/v1/production/orders')->assertOk()
        ->assertJsonPath('data.0.total_cost', 320);
});

it('exposes recipe CRUD and cost-breakdown via API', function (): void {
    $ing = makeIngredient($this->fx, 'Сироп', 50.0, 5);
    $dish = makeDish($this->fx, 'Латте');

    $create = postJson('/api/v1/recipes', [
        'product_id' => $dish->id,
        'yield_quantity' => 1,
        'instructions' => 'Steam milk',
        'items' => [
            ['ingredient_id' => $ing->id, 'quantity' => 0.03, 'waste_percentage' => 5],
        ],
    ]);
    $create->assertStatus(201);
    $recipeId = (int) $create->json('data.id');

    getJson('/api/v1/recipes')->assertOk()->assertJsonPath('data.0.id', $recipeId);

    $bd = getJson('/api/v1/products/'.$dish->id.'/cost-breakdown?warehouse_id='.$this->fx->warehouse->id);
    $bd->assertOk();
    expect((float) $bd->json('data.total_cost'))->toBe(1.5); // 0.03 * 50

    $produce = postJson('/api/v1/production/produce', [
        'recipe_id' => $recipeId,
        'qty' => 1,
        'warehouse_id' => $this->fx->warehouse->id,
    ]);
    $produce->assertStatus(201);
});

it('isolates recipes by tenant (rls)', function (): void {
    $ing = makeIngredient($this->fx, 'Соль', 5.0, 1);
    $dish = makeDish($this->fx, 'Суп');
    $this->production->upsertRecipe(
        $this->fx->tenant->id,
        $dish->id,
        1.0,
        null,
        [['ingredient_id' => $ing->id, 'quantity' => 0.01, 'waste_percentage' => 0]],
    );

    $other = AcceptanceFixture::make('prod2-'.uniqid());
    set_current_tenant_id($other->tenant->id);
    actingAs($other->user);

    getJson('/api/v1/recipes')->assertOk();
    expect(getJson('/api/v1/recipes')->json('data'))->toHaveCount(0);
});

it('processCompositeSale accounts for waste in gross write-off', function (): void {
    $meat = makeIngredient($this->fx, 'Мясо', 100.0, 10);
    $dish = makeDish($this->fx, 'Стейк');

    $this->production->upsertRecipe(
        $this->fx->tenant->id,
        $dish->id,
        1.0,
        null,
        [['ingredient_id' => $meat->id, 'quantity' => 0.25, 'waste_percentage' => 20]],
    );

    $order = \Autometria\Models\Order::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $this->fx->tenant->id,
        'location_id' => $this->fx->location->id,
        'customer_id' => $this->fx->customer->id,
        'number' => 'PROD-'.uniqid(),
        'status' => 'new',
        'payment_status' => 'unpaid',
        'total' => 1000,
        'scenario' => 'without_installation',
    ]);

    $item = OrderItem::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $this->fx->tenant->id,
        'order_id' => $order->id,
        'product_id' => $dish->id,
        'type' => 'product',
        'qty' => 2,
        'price' => 500,
        'discount' => 0,
        'snapshot' => ['warehouse_id' => $this->fx->warehouse->id],
    ]);

    $result = $this->production->processCompositeSale(
        $item,
        $this->fx->warehouse->id,
        false,
        $this->fx->user->id,
    );

    expect($result)->not->toBeNull();
    expect($result['composite'])->toBeTrue();
    // 2 * 0.25 gross = 0.5 written off (waste already baked into брутто)
    expect($result['ingredients'][0]['qty'])->toBe(0.5);
    expect($result['cost'])->toBe(50.0); // 0.5 * 100

    $avail = (float) Stock::query()->withoutGlobalScopes()
        ->where('product_id', $meat->id)->value('available');
    expect($avail)->toBe(9.5);
});
