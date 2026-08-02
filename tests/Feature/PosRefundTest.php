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
use Autometria\Models\OrderItem;
use Autometria\Models\Price;
use Autometria\Models\ProductService;
use Autometria\Models\StockBatch;
use Autometria\Services\Cash\CashShiftService;
use Autometria\Services\StockBatchService;
use Tests\Support\AcceptanceFixture;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

beforeEach(function (): void {
    $this->withoutMiddleware();
    config(['cache.default' => 'array']);

    $this->fx = AcceptanceFixture::make('refund-'.uniqid());
    set_current_tenant_id($this->fx->tenant->id);
    actingAs($this->fx->user);

    $this->shift = app(CashShiftService::class)->open(
        $this->fx->tenant->id,
        $this->fx->location->id,
        $this->fx->user->id,
        0,
    );
});

function makeProductWithPrice(object $fx, string $article, float $price): ProductService
{
    $p = ProductService::query()->forceCreate([
        'tenant_id' => $fx->tenant->id,
        'type' => 'product',
        'name' => 'Шина '.$article,
        'article' => $article,
        'base_price' => $price,
        'is_active' => true,
    ]);
    Price::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $fx->tenant->id,
        'product_id' => $p->id,
        'type' => 'retail',
        'price' => $price,
        'amount' => $price,
        'cost_price' => round($price * 0.7, 2),
    ]);

    return $p;
}

function sellViaOfflineReceipt(object $fx, int $productId, float $qty): array
{
    $payload = [
        'method' => 'cash',
        'amount_tendered' => 99999.0,
        'shift_id' => $fx->shiftId ?? null,
        'items' => [
            [
                'product_id' => $productId,
                'qty' => $qty,
                'warehouse_id' => $fx->warehouse->id,
                'type' => 'product',
            ],
        ],
    ];

    $response = postJson(
        '/api/v1/pos/offline-receipts',
        $payload,
        ['X-Idempotency-Key' => (string) \Illuminate\Support\Str::uuid()],
    );
    $response->assertStatus(201);
    $orderId = (int) ($response->json('data.order.id') ?? $response->json('data.id'));

    return ['order_id' => $orderId];
}

it('full refund restocks fifo and updates order status to refunded', function (): void {
    $product = makeProductWithPrice($this->fx, 'RFD-F-1', 1000.0);
    app(StockBatchService::class)->ingress(
        $this->fx->tenant->id,
        (int) $this->fx->warehouse->id,
        (int) $product->id,
        5.0,
        100.0,
        'BATCH-RFD-F',
        (int) $this->fx->user->id,
    );

    $sale = sellViaOfflineReceipt($this->fx, (int) $product->id, 2.0);
    $order = Order::query()->withoutGlobalScopes()->find($sale['order_id']);
    expect($order)->not->toBeNull();
    $orderItem = OrderItem::query()->withoutGlobalScopes()
        ->where('order_id', $order->id)->first();
    expect($orderItem)->not->toBeNull();

    $batchBefore = StockBatch::query()->withoutGlobalScopes()
        ->where('tenant_id', $this->fx->tenant->id)
        ->where('product_id', $product->id)
        ->where('batch_number', 'BATCH-RFD-F')
        ->first();
    $remainingBefore = (float) $batchBefore->remaining_qty;

    $resp = postJson('/api/v1/pos/refunds', [
        'order_id' => $order->id,
        'items' => [
            ['order_item_id' => $orderItem->id, 'qty' => 2.0],
        ],
    ]);
    $resp->assertStatus(201);

    $order->refresh();
    expect($order->status)->toBe(OrderStatusEnum::REFUNDED->value);

    $batchAfter = StockBatch::query()->withoutGlobalScopes()
        ->whereKey($batchBefore->id)
        ->first();
    expect((float) $batchAfter->remaining_qty)->toBe(round($remainingBefore + 2.0, 3));
});

it('partial refund updates order status to partially_refunded', function (): void {
    $product = makeProductWithPrice($this->fx, 'RFD-P-1', 1000.0);
    app(StockBatchService::class)->ingress(
        $this->fx->tenant->id,
        (int) $this->fx->warehouse->id,
        (int) $product->id,
        5.0,
        100.0,
        'BATCH-RFD-P',
        (int) $this->fx->user->id,
    );

    $sale = sellViaOfflineReceipt($this->fx, (int) $product->id, 3.0);
    $order = Order::query()->withoutGlobalScopes()->find($sale['order_id']);
    $orderItem = OrderItem::query()->withoutGlobalScopes()
        ->where('order_id', $order->id)->first();

    $resp = postJson('/api/v1/pos/refunds', [
        'order_id' => $order->id,
        'items' => [
            ['order_item_id' => $orderItem->id, 'qty' => 1.0],
        ],
    ]);
    $resp->assertStatus(201);
    $order->refresh();
    expect($order->status)->toBe(OrderStatusEnum::PARTIALLY_REFUNDED->value);

    $resp2 = postJson('/api/v1/pos/refunds', [
        'order_id' => $order->id,
        'items' => [
            ['order_item_id' => $orderItem->id, 'qty' => 2.0],
        ],
    ]);
    $resp2->assertStatus(201);
    $order->refresh();
    expect($order->status)->toBe(OrderStatusEnum::REFUNDED->value);
});

it('refund resolves inventory overdraft alerts', function (): void {
    $product = makeProductWithPrice($this->fx, 'RFD-O-1', 1000.0);
    // No stock → offline sale creates overdraft batch + alert.
    $sale = sellViaOfflineReceipt($this->fx, (int) $product->id, 2.0);
    $order = Order::query()->withoutGlobalScopes()->find($sale['order_id']);
    $orderItem = OrderItem::query()->withoutGlobalScopes()
        ->where('order_id', $order->id)->first();

    $alert = InventoryAlert::query()->withoutGlobalScopes()
        ->where('tenant_id', $this->fx->tenant->id)
        ->where('type', 'OVERDRAFT')
        ->latest('id')->first();
    expect($alert)->not->toBeNull();

    $resp = postJson('/api/v1/pos/refunds', [
        'order_id' => $order->id,
        'items' => [
            ['order_item_id' => $orderItem->id, 'qty' => 2.0],
        ],
    ]);
    $resp->assertStatus(201);

    $overdraft = StockBatch::query()->withoutGlobalScopes()
        ->where('tenant_id', $this->fx->tenant->id)
        ->where('product_id', $product->id)
        ->where('is_overdraft', true)
        ->first();
    expect($overdraft)->not->toBeNull();
    expect((float) $overdraft->remaining_qty)->toBe(0.0);
});
