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
use Autometria\Models\ProductService;
use Autometria\Models\StockBatch;
use Autometria\Models\StockLotDeduction;
use Autometria\Services\Cash\CashShiftService;
use Tests\Support\AcceptanceFixture;
use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

beforeEach(function (): void {
    $this->fx = AcceptanceFixture::make('pos-'.uniqid());
    set_current_tenant_id($this->fx->tenant->id);
    actingAs($this->fx->user);
    // API endpoints are stateless; disable web/CSRF middleware for feature tests.
    $this->withSession(['_token' => 'test-token']);
    $this->withHeader('X-CSRF-TOKEN', 'test-token');
    $this->withCookie('XSRF-TOKEN', 'test-token');

    // Открываем активную смену (24ч лимит от текущего момента).
    $this->shift = app(CashShiftService::class)->open(
        $this->fx->tenant->id,
        $this->fx->location->id,
        $this->fx->user->id,
        0,
    );
});

/**
 * Test 1: идемпотентность офлайн-чека по X-Idempotency-Key.
 * Повторная отправка не дублирует заказ и списания.
 */
it('offline receipt idempotency prevents duplicates', function (): void {
    $product = ProductService::query()->forceCreate([
        'tenant_id' => $this->fx->tenant->id,
        'type' => 'product',
        'name' => 'Шина idempotent',
        'article' => 'IDEM-1',
        'base_price' => 1000.0,
        'is_active' => true,
    ]);

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

    // Повторная отправка того же ключа.
    $second = postJson('/api/v1/pos/offline-receipts', $payload, $headers);
    $second->assertStatus(200);

    $ordersAfterSecond = Order::query()->withoutGlobalScopes()
        ->where('tenant_id', $this->fx->tenant->id)->count();
    $deductionsAfterSecond = StockLotDeduction::query()->withoutGlobalScopes()
        ->where('tenant_id', $this->fx->tenant->id)->count();

    // Без дублирования.
    expect($ordersAfterSecond)->toBe($ordersAfterFirst);
    expect($deductionsAfterSecond)->toBe($deductionsAfterFirst);
});

/**
 * Test 2: офлайн-чек при нулевом остатке создаёт овердрафт-партию и уведомление.
 */
it('offline receipt handles stock overdraft gracefully', function (): void {
    $product = ProductService::query()->forceCreate([
        'tenant_id' => $this->fx->tenant->id,
        'type' => 'product',
        'name' => 'Шина overdraft',
        'article' => 'OVD-1',
        'base_price' => 2000.0,
        'is_active' => true,
    ]);
    // Намеренно НЕ создаём партии — остаток нулевой.

    $payload = [
        'method' => 'cash',
        'amount_tendered' => 2500.0,
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

    // Овердрафт-партия создана.
    $overdraftBatch = StockBatch::query()->withoutGlobalScopes()
        ->where('tenant_id', $this->fx->tenant->id)
        ->where('product_id', $product->id)
        ->where('is_overdraft', true)
        ->first();
    expect($overdraftBatch)->not->toBeNull();
    expect((float) $overdraftBatch->remaining_qty)->toBe(-2.0);

    // Уведомление кладовщику.
    $alert = InventoryAlert::query()->withoutGlobalScopes()
        ->where('tenant_id', $this->fx->tenant->id)
        ->where('type', 'OVERDRAFT')
        ->first();
    expect($alert)->not->toBeNull();

    // Заказ в статусе completed_with_overdraft.
    $order = Order::query()->withoutGlobalScopes()
        ->where('tenant_id', $this->fx->tenant->id)
        ->latest('id')->first();
    expect($order->status)->toBe(OrderStatusEnum::COMPLETED_WITH_OVERDRAFT->value);
});

/**
 * Test 3: просроченная смена (>24ч) блокирует синхронизацию офлайн-чека.
 */
it('expired shift blocks offline receipt sync', function (): void {
    // Сдвигаем expires_at смены в прошлое.
    $this->shift->expires_at = now()->subHour();
    $this->shift->save();

    $product = ProductService::query()->forceCreate([
        'tenant_id' => $this->fx->tenant->id,
        'type' => 'product',
        'name' => 'Шина expired',
        'article' => 'EXP-1',
        'base_price' => 1500.0,
        'is_active' => true,
    ]);

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
    $response->dump();
    $response->assertStatus(422);
    $response->assertJsonFragment(['code' => 'SHIFT_EXPIRED']);
});
