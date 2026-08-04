<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 *
 * TV board cache eviction on Order status mutation (afterCommit).
 */

declare(strict_types=1);

use Autometria\DTOs\CreateOrderDTO;
use Autometria\Models\Issuance;
use Autometria\Models\Order;
use Autometria\Services\IssuanceService;
use Autometria\Services\OrderService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\AcceptanceFixture;

beforeEach(function (): void {
    $this->withoutMiddleware([
        \Autometria\Http\Middleware\EnsurePermission::class,
        \Autometria\Http\Middleware\EnforceLocationAccess::class,
    ]);
    config(['cache.default' => 'array']);
    Cache::flush();
});

it('evicts tv board cache when IssuanceService changes order status', function (): void {
    $fx = AcceptanceFixture::make('tv-evict-a-'.uniqid());
    set_current_tenant_id($fx->tenant->id);

    $order = app(OrderService::class)->create(new CreateOrderDTO(
        tenantId: $fx->tenant->id,
        customerId: $fx->customer->id,
        locationId: $fx->location->id,
        assignedSellerId: $fx->user->id,
        masterId: $fx->master->id,
        items: [[
            'type' => 'product',
            'product_id' => $fx->product->id,
            'qty' => 1,
            'price' => 100.0,
            'warehouse_id' => $fx->warehouse->id,
        ]],
        scenario: 'with_installation',
        vehicleId: $fx->vehicle->id,
    ), $fx->user->id);

    expect($order->status)->toBe(Order::STATUS_CREATED);

    $expectedKey = sprintf('tv_board:%d:%d', $fx->tenant->id, $fx->location->id);

    Cache::spy();

    $item = $order->orderItems->firstOrFail();
    app(IssuanceService::class)->issue(
        $fx->tenant->id,
        $order->id,
        $item->id,
        1.0,
        $fx->user->id,
        Issuance::BASIS_TO_CUSTOMER,
    );

    $order->refresh();
    expect($order->status)->toBe(Order::STATUS_IN_PROGRESS);

    Cache::shouldHaveReceived('forget')->with($expectedKey);
});

it('does not evict tv board cache when only order number changes', function (): void {
    $fx = AcceptanceFixture::make('tv-evict-b-'.uniqid());
    set_current_tenant_id($fx->tenant->id);

    $order = app(OrderService::class)->create(new CreateOrderDTO(
        tenantId: $fx->tenant->id,
        customerId: $fx->customer->id,
        locationId: $fx->location->id,
        assignedSellerId: $fx->user->id,
        masterId: $fx->master->id,
        items: [[
            'type' => 'service',
            'product_id' => $fx->service->id,
            'qty' => 1,
            'price' => 500.0,
        ]],
        scenario: 'with_installation',
        vehicleId: $fx->vehicle->id,
    ), $fx->user->id);

    Cache::spy();

    $order->update(['number' => 'TV-NUMBER-ONLY-'.uniqid()]);

    Cache::shouldNotHaveReceived('forget');
});

it('does not evict tv board cache when status mutation is rolled back', function (): void {
    $fx = AcceptanceFixture::make('tv-evict-c-'.uniqid());
    set_current_tenant_id($fx->tenant->id);

    $order = app(OrderService::class)->create(new CreateOrderDTO(
        tenantId: $fx->tenant->id,
        customerId: $fx->customer->id,
        locationId: $fx->location->id,
        assignedSellerId: $fx->user->id,
        masterId: $fx->master->id,
        items: [[
            'type' => 'service',
            'product_id' => $fx->service->id,
            'qty' => 1,
            'price' => 500.0,
        ]],
        scenario: 'with_installation',
        vehicleId: $fx->vehicle->id,
    ), $fx->user->id);

    expect($order->status)->toBe(Order::STATUS_CREATED);

    Cache::spy();

    DB::beginTransaction();
    try {
        $order->update(['status' => Order::STATUS_IN_PROGRESS]);
        expect($order->wasChanged('status'))->toBeTrue();
    } finally {
        DB::rollBack();
    }

    $order->refresh();
    expect($order->status)->toBe(Order::STATUS_CREATED);

    Cache::shouldNotHaveReceived('forget');
});
