<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

use Autometria\Enums\SupplierOrderStatusEnum;
use Autometria\Models\AuditLog;
use Autometria\Models\DeliverySchedule;
use Autometria\Models\ProductService;
use Autometria\Models\Stock;
use Autometria\Models\StockBatch;
use Autometria\Services\Purchasing\SupplierOrderService;
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

    $this->fx = AcceptanceFixture::make('po-'.uniqid());
    set_current_tenant_id($this->fx->tenant->id);
    actingAs($this->fx->user);

    $this->svc = app(SupplierOrderService::class);

    Stock::query()->withoutGlobalScopes()
        ->whereKey($this->fx->stock->id)
        ->update(['actual' => 0, 'reserved' => 0, 'available' => 0]);
    $this->fx->stock->refresh();
});

it('createOrder creates DRAFT supplier order with items', function (): void {
    $supplier = $this->svc->createSupplier($this->fx->tenant->id, [
        'name' => 'ООО Поставщик',
        'inn' => '7701234567',
    ]);

    $order = $this->svc->createOrder($this->fx->tenant->id, [
        'supplier_id' => $supplier->id,
        'warehouse_id' => $this->fx->warehouse->id,
        'expected_delivery' => now()->addDays(3)->toDateString(),
        'items' => [
            [
                'product_id' => $this->fx->product->id,
                'qty' => 10,
                'unit_price' => 100,
            ],
        ],
    ], $this->fx->user->id);

    expect($order->status)->toBe(SupplierOrderStatusEnum::DRAFT->value);
    expect((float) $order->total_amount)->toBe(1000.0);
    expect($order->items)->toHaveCount(1);
    expect((float) $order->items->first()->qty)->toBe(10.0);

    postJson('/api/v1/supplier-orders', [
        'supplier_id' => $supplier->id,
        'warehouse_id' => $this->fx->warehouse->id,
        'items' => [
            ['product_id' => $this->fx->product->id, 'qty' => 2, 'unit_price' => 50],
        ],
    ])->assertCreated()
        ->assertJsonPath('data.status', 'DRAFT');
});

it('receiveGoods increases stock and writes AuditLog', function (): void {
    $supplier = $this->svc->createSupplier($this->fx->tenant->id, ['name' => 'Склад-Снаб']);
    $order = $this->svc->createOrder($this->fx->tenant->id, [
        'supplier_id' => $supplier->id,
        'warehouse_id' => $this->fx->warehouse->id,
        'items' => [
            ['product_id' => $this->fx->product->id, 'qty' => 8, 'unit_price' => 120],
        ],
    ], $this->fx->user->id);

    $confirmed = $this->svc->confirmOrder($this->fx->tenant->id, (int) $order->id, $this->fx->user->id);
    expect($confirmed->status)->toBe(SupplierOrderStatusEnum::CONFIRMED->value);
    expect(
        DeliverySchedule::query()->withoutGlobalScopes()
            ->where('supplier_order_id', $order->id)
            ->count()
    )->toBe(1);

    $received = $this->svc->receiveGoods(
        $this->fx->tenant->id,
        (int) $order->id,
        [['product_id' => $this->fx->product->id, 'qty' => 5, 'cost_price' => 110]],
        $this->fx->user->id,
    );

    expect($received->status)->toBe(SupplierOrderStatusEnum::PARTIALLY_RECEIVED->value);

    $this->fx->stock->refresh();
    expect((float) $this->fx->stock->actual)->toBe(5.0);
    expect((float) $this->fx->stock->available)->toBe(5.0);

    $batch = StockBatch::query()->withoutGlobalScopes()
        ->where('tenant_id', $this->fx->tenant->id)
        ->where('supplier_order_id', $order->id)
        ->first();
    expect($batch)->not->toBeNull();
    expect((float) $batch->remaining_qty)->toBe(5.0);
    expect((float) $batch->cost_price)->toBe(110.0);

    expect(
        AuditLog::query()->withoutGlobalScopes()
            ->where('tenant_id', $this->fx->tenant->id)
            ->where('action', 'purchases.order.received')
            ->exists()
    )->toBeTrue();

    postJson('/api/v1/supplier-orders/'.$order->id.'/receive', [
        'items' => [['product_id' => $this->fx->product->id, 'qty' => 3]],
    ])->assertOk()
        ->assertJsonPath('data.status', 'RECEIVED');

    $this->fx->stock->refresh();
    expect((float) $this->fx->stock->available)->toBe(8.0);
});

it('planReplenishment suggests qty for products below min_stock', function (): void {
    ProductService::query()->withoutGlobalScopes()
        ->whereKey($this->fx->product->id)
        ->update([
            'min_stock' => 20,
            'max_stock' => 50,
            'reorder_point' => 20,
        ]);

    Stock::query()->withoutGlobalScopes()
        ->whereKey($this->fx->stock->id)
        ->update(['actual' => 5, 'reserved' => 0, 'available' => 5]);

    $plan = $this->svc->planReplenishment($this->fx->tenant->id, $this->fx->warehouse->id);

    expect($plan)->not->toBeEmpty();
    $row = collect($plan)->firstWhere('product_id', $this->fx->product->id);
    expect($row)->not->toBeNull();
    expect((float) $row['available'])->toBe(5.0);
    expect((float) $row['suggested_qty'])->toBe(45.0); // 50 - 5

    getJson('/api/v1/purchases/replenishment-plan?warehouse_id='.$this->fx->warehouse->id)
        ->assertOk()
        ->assertJsonPath('data.0.product_id', $this->fx->product->id);

    getJson('/api/v1/suppliers')->assertOk();
    postJson('/api/v1/suppliers', ['name' => 'API Supplier'])->assertCreated();
});
