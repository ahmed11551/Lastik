<?php

declare(strict_types=1);

use App\DTOs\CreateOrderDTO;
use App\Models\Order;
use App\Services\OrderService;
use Tests\Support\AcceptanceFixture;

/**
 * Приёмка 49.1 — изоляция организаций (tenant scope).
 */
it('hides foreign tenant order from scoped queries', function (): void {
    $tenantA = AcceptanceFixture::make('iso-a-'.uniqid());
    $tenantB = AcceptanceFixture::make('iso-b-'.uniqid());

    $orderB = app(OrderService::class)->create(new CreateOrderDTO(
        tenantId: $tenantB->tenant->id,
        customerId: $tenantB->customer->id,
        locationId: $tenantB->location->id,
        assignedSellerId: $tenantB->user->id,
        masterId: 0,
        items: [[
            'type' => 'product',
            'product_id' => $tenantB->product->id,
            'qty' => 1,
            'price' => 1000,
            'warehouse_id' => $tenantB->warehouse->id,
        ]],
        scenario: 'without_installation',
    ), $tenantB->user->id);

    app()->instance('current_tenant_id', $tenantA->tenant->id);

    expect(Order::query()->whereKey($orderB->id)->first())->toBeNull();
    expect(Order::query()->get())->toHaveCount(0);

    // без scope заказ существует
    expect(
        Order::query()->withoutGlobalScopes()->whereKey($orderB->id)->exists()
    )->toBeTrue();
});

it('lists only current tenant orders', function (): void {
    $tenantA = AcceptanceFixture::make('list-a-'.uniqid());
    $tenantB = AcceptanceFixture::make('list-b-'.uniqid());

    $orderA = app(OrderService::class)->create(new CreateOrderDTO(
        tenantId: $tenantA->tenant->id,
        customerId: $tenantA->customer->id,
        locationId: $tenantA->location->id,
        assignedSellerId: $tenantA->user->id,
        masterId: 0,
        items: [[
            'type' => 'product',
            'product_id' => $tenantA->product->id,
            'qty' => 1,
            'price' => 1000,
            'warehouse_id' => $tenantA->warehouse->id,
        ]],
        scenario: 'without_installation',
    ), $tenantA->user->id);

    app(OrderService::class)->create(new CreateOrderDTO(
        tenantId: $tenantB->tenant->id,
        customerId: $tenantB->customer->id,
        locationId: $tenantB->location->id,
        assignedSellerId: $tenantB->user->id,
        masterId: 0,
        items: [[
            'type' => 'product',
            'product_id' => $tenantB->product->id,
            'qty' => 1,
            'price' => 1000,
            'warehouse_id' => $tenantB->warehouse->id,
        ]],
        scenario: 'without_installation',
    ), $tenantB->user->id);

    app()->instance('current_tenant_id', $tenantA->tenant->id);

    $list = Order::query()->get();
    expect($list)->toHaveCount(1);
    expect($list->first()->id)->toBe($orderA->id);
});
