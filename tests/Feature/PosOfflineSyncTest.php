<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

use Autometria\Enums\OrderStatusEnum;
use Autometria\Models\InventoryAlert;
use Autometria\Models\Order;
use Autometria\Models\Price;
use Autometria\Models\ProductService;
use Autometria\Models\StockBatch;
use Autometria\Models\StockLotDeduction;
use Autometria\Services\Cash\CashShiftService;
use Tests\Support\AcceptanceFixture;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

beforeEach(function (): void {
    // Exclude 500s from Redis throttle / license middleware in feature tests.
    $this->withoutMiddleware();
    // Тестовое окружение: кэш в array (redis-расширение недоступно в контейнере тестов).
    config(['cache.default' => 'array']);

    $this->fx = AcceptanceFixture::make('pos-'.uniqid());
    set_current_tenant_id($this->fx->tenant->id);
    actingAs($this->fx->user);

    // Открываем активную смену (24ч лимит от текущего момента).
    $this->shift = app(CashShiftService::class)->open(
        $this->fx->tenant->id,
        $this->fx->location->id,
        $this->fx->user->id,
        0,
    );
});

function seedPosProduct(object $fx, string $article, float $price): ProductService
{
    $product = ProductService::query()->forceCreate([
        'tenant_id' => $fx->tenant->id,
        'type' => 'product',
        'name' => 'Шина '.$article,
        'article' => $article,
        'base_price' => $price,
        'is_active' => true,
    ]);

    Price::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $fx->tenant->id,
        'product_id' => $product->id,
        'type' => 'retail',
        'price' => $price,
        'amount' => $price,
        'cost_price' => round($price * 0.7, 2),
    ]);

    return $product;
}

/**
 * Test 1: идемпотентность офлайн-чека по X-Idempotency-Key.
 */
it('offline receipt idempotency prevents duplicates', function (): void {
    $product = seedPosProduct($this->fx, 'IDEM-1', 1000.0);

    // Онлайн-остаток есть — без овердрафта (проверяем только идемпотентность).
    app(\Autometria\Services\StockBatchService::class)->ingress(
        $this->fx->tenant->id,
        $this->fx->warehouse->id,
        $product->id,
        5.0,
        700.0,
    );

    $payload = [
        'method' => 'cash',
        'amount_tendered' => 1500.0,
        'items' => [
            [
                'product_id' => $product->id,
                'qty' => 1.0,
                'warehouse_id' => $this->fx->warehouse->id,
                'type' => 'product',
            ],
        ],
    ];

    $key = (string) \Illuminate\Support\Str::uuid();
    $headers = ['X-Idempotency-Key' => $key];

    $first = postJson('/api/v1/pos/offline-receipts', $payload, $headers);
    $first->assertStatus(201);

    $ordersAfterFirst = Order::query()->withoutGlobalScopes()
        ->where('tenant_id', $this->fx->tenant->id)->count();
    $deductionsAfterFirst = StockLotDeduction::query()->withoutGlobalScopes()
        ->where('tenant_id', $this->fx->tenant->id)->count();

    $second = postJson('/api/v1/pos/offline-receipts', $payload, $headers);
    $second->assertStatus(200);

    $ordersAfterSecond = Order::query()->withoutGlobalScopes()
        ->where('tenant_id', $this->fx->tenant->id)->count();
    $deductionsAfterSecond = StockLotDeduction::query()->withoutGlobalScopes()
        ->where('tenant_id', $this->fx->tenant->id)->count();

    expect($ordersAfterSecond)->toBe($ordersAfterFirst);
    expect($deductionsAfterSecond)->toBe($deductionsAfterFirst);
});

/**
 * Test 2: офлайн-чек при нулевом остатке → овердрафт-партия + alert.
 */
it('offline receipt handles stock overdraft gracefully', function (): void {
    $product = seedPosProduct($this->fx, 'OVD-1', 2000.0);
    // Намеренно НЕ создаём партии — остаток нулевой.

    $payload = [
        'method' => 'cash',
        'amount_tendered' => 4000.0,
        'items' => [
            [
                'product_id' => $product->id,
                'qty' => 2.0,
                'warehouse_id' => $this->fx->warehouse->id,
                'type' => 'product',
            ],
        ],
    ];

    $key = (string) \Illuminate\Support\Str::uuid();
    $response = postJson('/api/v1/pos/offline-receipts', $payload, ['X-Idempotency-Key' => $key]);
    $response->assertStatus(201);

    $overdraftBatch = StockBatch::query()->withoutGlobalScopes()
        ->where('tenant_id', $this->fx->tenant->id)
        ->where('product_id', $product->id)
        ->where('is_overdraft', true)
        ->first();
    expect($overdraftBatch)->not->toBeNull();
    expect((float) $overdraftBatch->remaining_qty)->toBe(-2.0);

    $alert = InventoryAlert::query()->withoutGlobalScopes()
        ->where('tenant_id', $this->fx->tenant->id)
        ->where('type', 'OVERDRAFT')
        ->first();
    expect($alert)->not->toBeNull();

    $order = Order::query()->withoutGlobalScopes()
        ->where('tenant_id', $this->fx->tenant->id)
        ->latest('id')->first();
    expect($order->status)->toBe(OrderStatusEnum::COMPLETED_WITH_OVERDRAFT->value);
});

/**
 * Test 3: просроченная смена (>24ч) блокирует синхронизацию офлайн-чека.
 */
it('expired shift blocks offline receipt sync', function (): void {
    $this->shift->expires_at = now()->subHour();
    $this->shift->save();

    $product = seedPosProduct($this->fx, 'EXP-1', 1500.0);

    $payload = [
        'method' => 'cash',
        'amount_tendered' => 1500.0,
        'items' => [
            [
                'product_id' => $product->id,
                'qty' => 1.0,
                'warehouse_id' => $this->fx->warehouse->id,
                'type' => 'product',
            ],
        ],
    ];

    $key = (string) \Illuminate\Support\Str::uuid();
    $response = postJson('/api/v1/pos/offline-receipts', $payload, ['X-Idempotency-Key' => $key]);
    $response->assertStatus(422);
    $response->assertJsonFragment(['code' => 'SHIFT_EXPIRED']);
});
