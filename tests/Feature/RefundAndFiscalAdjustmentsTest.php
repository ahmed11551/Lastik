<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

use Autometria\Enums\FiscalReceiptStatus;
use Autometria\Enums\FiscalReceiptType;
use Autometria\Enums\OrderStatusEnum;
use Autometria\Enums\MarkingValidationStatusEnum;
use Autometria\Models\MarkingValidation;
use Autometria\Models\Order;
use Autometria\Models\OrderItem;
use Autometria\Models\Price;
use Autometria\Models\ProductService;
use Autometria\Models\Refund;
use Autometria\Models\Stock;
use Autometria\Models\StockBatch;
use Autometria\Models\StockLotDeduction;
use Autometria\Services\Cash\CashShiftService;
use Autometria\Services\RefundService;
use Autometria\Services\StockBatchService;
use Tests\Support\AcceptanceFixture;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

beforeEach(function (): void {
    $this->withoutMiddleware();
    config(['cache.default' => 'array']);
    putenv('MARKING_MOCK_MODE=true');
    $_ENV['MARKING_MOCK_MODE'] = 'true';

    $this->fx = AcceptanceFixture::make('ref-'.uniqid());
    set_current_tenant_id($this->fx->tenant->id);
    actingAs($this->fx->user);

    $this->shift = app(CashShiftService::class)->open(
        $this->fx->tenant->id,
        $this->fx->location->id,
        $this->fx->user->id,
        0,
    );
});

function seedRefundableSale(object $fx, float $price = 1000.0, float $qty = 5.0): array
{
    $product = ProductService::query()->forceCreate([
        'tenant_id' => $fx->tenant->id,
        'type' => 'product',
        'name' => 'Refund tyre',
        'article' => 'REF-'.uniqid(),
        'base_price' => $price,
        'is_active' => true,
        'is_marked' => false,
    ]);

    Price::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $fx->tenant->id,
        'product_id' => $product->id,
        'type' => 'retail',
        'price' => $price,
        'amount' => $price,
        'cost_price' => 700,
    ]);

    $batches = app(StockBatchService::class);
    $old = $batches->ingress($fx->tenant->id, $fx->warehouse->id, $product->id, 3.0, 700.0, 'B-OLD');
    $old->update(['received_at' => now()->subDay()]);
    $batches->ingress($fx->tenant->id, $fx->warehouse->id, $product->id, 5.0, 800.0, 'B-NEW');

    $res = postJson('/api/v1/pos/checkout', [
        'method' => 'cash',
        'amount_tendered' => $price * $qty + 100,
        'items' => [
            [
                'product_id' => $product->id,
                'qty' => $qty,
                'warehouse_id' => $fx->warehouse->id,
                'type' => 'product',
            ],
        ],
    ]);
    $res->assertStatus(201);

    $orderId = (int) $res->json('data.order.id');
    $orderItem = OrderItem::query()->withoutGlobalScopes()
        ->where('order_id', $orderId)
        ->firstOrFail();

    return compact('product', 'orderId', 'orderItem', 'qty', 'price');
}

it('reverses fifo write-off onto original stock batches', function (): void {
    $sale = seedRefundableSale($this->fx, 1000.0, 4.0);

    $old = StockBatch::query()->withoutGlobalScopes()
        ->where('batch_number', 'B-OLD')->firstOrFail();
    $new = StockBatch::query()->withoutGlobalScopes()
        ->where('batch_number', 'B-NEW')->firstOrFail();

    // After sale of 4: old 0 remaining (3 taken), new 4 remaining (1 taken from 5)
    expect((float) $old->fresh()->remaining_qty)->toBe(0.0);
    expect((float) $new->fresh()->remaining_qty)->toBe(4.0);

    $stockBefore = (float) Stock::query()->withoutGlobalScopes()
        ->where('product_id', $sale['product']->id)
        ->where('warehouse_id', $this->fx->warehouse->id)
        ->value('actual');

    $refund = app(RefundService::class)->refundOrder(
        $this->fx->tenant->id,
        $sale['orderId'],
        [['order_item_id' => $sale['orderItem']->id, 'qty' => 4.0]],
        $this->fx->user->id,
        'Клиентский возврат',
        $this->shift->id,
    );

    expect($refund->status)->toBe('completed');
    expect((float) $refund->total_amount)->toBe(4000.0);

    // Restored: LIFO → new first (+1 to 5), then old (+3 to 3)
    expect((float) $new->fresh()->remaining_qty)->toBe(5.0);
    expect((float) $old->fresh()->remaining_qty)->toBe(3.0);

    $stockAfter = (float) Stock::query()->withoutGlobalScopes()
        ->where('product_id', $sale['product']->id)
        ->where('warehouse_id', $this->fx->warehouse->id)
        ->value('actual');
    expect($stockAfter)->toBe($stockBefore + 4.0);

    $order = Order::query()->withoutGlobalScopes()->findOrFail($sale['orderId']);
    expect($order->status)->toBe(OrderStatusEnum::REFUNDED->value);

    expect($refund->fiscalReceipt)->not->toBeNull();
    expect($refund->fiscalReceipt->operation)->toBe(FiscalReceiptType::SELL_REFUND);
    expect($refund->fiscalReceipt->status)->toBe(FiscalReceiptStatus::FISCALIZED);

    $deductions = StockLotDeduction::query()->withoutGlobalScopes()
        ->where('order_item_id', $sale['orderItem']->id)
        ->get();
    expect((float) $deductions->sum('refunded_qty'))->toBe(4.0);
});

it('creates sell_refund fiscal receipt via pos refunds endpoint', function (): void {
    $sale = seedRefundableSale($this->fx, 500.0, 2.0);

    $res = postJson('/api/v1/pos/refunds', [
        'order_id' => $sale['orderId'],
        'reason' => 'Брак',
        'items' => [
            ['order_item_id' => $sale['orderItem']->id, 'qty' => 1.0],
        ],
    ]);

    $res->assertStatus(201);
    expect($res->json('data.fiscal_receipt.operation'))->toBe('sell_refund');
    expect($res->json('data.fiscal_receipt.status'))->toBe('fiscalized');

    $order = Order::query()->withoutGlobalScopes()->findOrFail($sale['orderId']);
    expect($order->status)->toBe(OrderStatusEnum::PARTIALLY_REFUNDED->value);

    expect(Refund::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('unbinds marking code on refund of marked product', function (): void {
    $product = ProductService::query()->forceCreate([
        'tenant_id' => $this->fx->tenant->id,
        'type' => 'product',
        'name' => 'Marked shoes',
        'article' => 'MS-'.uniqid(),
        'base_price' => 2000,
        'is_active' => true,
        'is_marked' => true,
        'marking_type' => 'SHOES',
    ]);
    Price::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $this->fx->tenant->id,
        'product_id' => $product->id,
        'type' => 'retail',
        'price' => 2000,
        'amount' => 2000,
        'cost_price' => 1000,
    ]);
    app(StockBatchService::class)->ingress(
        $this->fx->tenant->id,
        $this->fx->warehouse->id,
        $product->id,
        2.0,
        1000.0,
    );

    $mark = '010460043900001421sN&<3!91800092dGVzdA==';
    $checkout = postJson('/api/v1/pos/checkout', [
        'method' => 'cash',
        'amount_tendered' => 2500,
        'items' => [[
            'product_id' => $product->id,
            'qty' => 1,
            'warehouse_id' => $this->fx->warehouse->id,
            'type' => 'product',
            'marking_code' => $mark,
        ]],
    ]);
    $checkout->assertStatus(201);
    $orderId = (int) $checkout->json('data.order.id');
    $orderItem = OrderItem::query()->withoutGlobalScopes()->where('order_id', $orderId)->firstOrFail();

    $res = postJson('/api/v1/orders/'.$orderId.'/refunds', [
        'order_id' => $orderId,
        'items' => [['order_item_id' => $orderItem->id, 'qty' => 1]],
        'reason' => 'Возврат маркированного',
    ]);
    $res->assertStatus(201);

    $unbound = MarkingValidation::query()->withoutGlobalScopes()
        ->where('tenant_id', $this->fx->tenant->id)
        ->where('marking_code', $mark)
        ->where('status', MarkingValidationStatusEnum::UNBOUND->value)
        ->exists();
    expect($unbound)->toBeTrue();
});
