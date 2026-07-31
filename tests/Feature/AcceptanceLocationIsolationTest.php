<?php

/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author Себиев Ахмед Сулейманович
 * @license Proprietary & Confidential.
 */

declare(strict_types=1);

use Autometria\DTOs\CreateOrderDTO;
use Autometria\Models\CashShift;
use Autometria\Models\Location;
use Autometria\Models\Order;
use Autometria\Models\Role;
use Autometria\Models\User;
use Autometria\Policies\OrderPolicy;
use Autometria\Services\OrderService;
use Illuminate\Support\Facades\Hash;
use Tests\Support\AcceptanceFixture;

/**
 * Приёмка п. 8 / 49.1 — изоляция точек.
 */
beforeEach(function (): void {
    $this->fx = AcceptanceFixture::make('loc-'.uniqid());
});

it('blocks seller of point A from viewing order of point B', function (): void {
    $fx = $this->fx;

    $pointB = Location::query()->withoutGlobalScopes()->create([
        'tenant_id' => $fx->tenant->id,
        'name' => 'Точка Б',
        'address' => 'Юг',
        'timezone' => 'Europe/Moscow',
        'is_active' => true,
    ]);

    $sellerRole = Role::query()->withoutGlobalScopes()->create([
        'tenant_id' => $fx->tenant->id,
        'name' => 'Seller point',
        'slug' => 'seller_point',
        'permissions' => ['orders.view', 'orders.create'],
    ]);

    $sellerA = User::query()->create([
        'tenant_id' => $fx->tenant->id,
        'location_id' => $fx->location->id,
        'role_id' => $sellerRole->id,
        'name' => 'Seller A',
        'email' => 'sa-'.uniqid().'@lastik.local',
        'password_hash' => Hash::make('password'),
        'is_active' => true,
    ]);

    // смена и заказ на точке B
    $shiftB = CashShift::query()->withoutGlobalScopes()->create([
        'tenant_id' => $fx->tenant->id,
        'location_id' => $pointB->id,
        'user_id' => $fx->user->id,
        'opened_by' => $fx->user->id,
        'status' => 'opened',
        'opened_at' => now(),
    ]);

    set_current_tenant_id($fx->tenant->id);

    $orderB = Order::query()->withoutGlobalScopes()->create([
        'tenant_id' => $fx->tenant->id,
        'location_id' => $pointB->id,
        'customer_id' => $fx->customer->id,
        'scenario' => 'without_installation',
        'status' => Order::STATUS_CREATED,
        'payment_status' => 'unpaid',
        'shift_id' => $shiftB->id,
        'assigned_seller_id' => $fx->user->id,
        'total' => 0,
        'created_by' => $fx->user->id,
    ]);

    $policy = new OrderPolicy;
    expect($policy->view($sellerA, $orderB))->toBeFalse();

    // admin с locations.all / admin.dashboard видит
    expect($policy->view($fx->user, $orderB))->toBeTrue();
});

it('lists orders only for current location when location_id is set', function (): void {
    $fx = $this->fx;

    $pointB = Location::query()->withoutGlobalScopes()->create([
        'tenant_id' => $fx->tenant->id,
        'name' => 'Точка Б2',
        'is_active' => true,
    ]);

    app(OrderService::class)->create(new CreateOrderDTO(
        tenantId: $fx->tenant->id,
        customerId: $fx->customer->id,
        locationId: $fx->location->id,
        assignedSellerId: $fx->user->id,
        masterId: 0,
        items: [[
            'type' => 'product',
            'product_id' => $fx->product->id,
            'qty' => 1,
            'price' => 500,
            'warehouse_id' => $fx->warehouse->id,
        ]],
        scenario: 'without_installation',
    ), $fx->user->id);

    Order::query()->withoutGlobalScopes()->create([
        'tenant_id' => $fx->tenant->id,
        'location_id' => $pointB->id,
        'customer_id' => $fx->customer->id,
        'scenario' => 'without_installation',
        'status' => Order::STATUS_CREATED,
        'payment_status' => 'unpaid',
        'shift_id' => $fx->shift->id,
        'total' => 0,
        'created_by' => $fx->user->id,
    ]);

    set_current_tenant_id($fx->tenant->id);
    app()->instance('current_location_id', $fx->location->id);

    $list = Order::query()->where('location_id', location_id())->get();
    expect($list)->toHaveCount(1);
    expect((int) $list->first()->location_id)->toBe($fx->location->id);
});
