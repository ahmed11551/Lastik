<?php

/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author Себиев Ахмед Сулейманович
 * @license Proprietary & Confidential.
 */

declare(strict_types=1);

use Autometria\DTOs\CreateOrderDTO;
use Autometria\Models\AuditLog;
use Autometria\Models\Customer;
use Autometria\Models\CustomerMerge;
use Autometria\Models\Order;
use Autometria\Models\Vehicle;
use Autometria\Services\CustomerMergeService;
use Autometria\Services\OrderService;
use Tests\Support\AcceptanceFixture;

/**
 * Приёмка 49.18 — объединение дублей.
 */
beforeEach(function (): void {
    $this->fx = AcceptanceFixture::make('49-18-'.uniqid());
});

it('merges duplicate customer into primary keeping orders vehicles and audit trail', function (): void {
    $fx = $this->fx;

    $duplicate = Customer::query()->withoutGlobalScopes()->create([
        'tenant_id' => $fx->tenant->id,
        'type' => Customer::TYPE_INDIVIDUAL,
        'name' => 'Иван Клиент Дубль',
        'legal_name' => 'Иван Клиент Дубль',
        'phone' => $fx->customer->phone, // тот же телефон — кандидат в дубли
        'email' => 'dup@ex.com',
    ]);

    $dupVehicle = Vehicle::query()->withoutGlobalScopes()->create([
        'tenant_id' => $fx->tenant->id,
        'customer_id' => $duplicate->id,
        'plate' => 'X999XX99',
        'brand' => 'VW',
        'model' => 'Polo',
    ]);

    $order = app(OrderService::class)->create(new CreateOrderDTO(
        tenantId: $fx->tenant->id,
        customerId: $duplicate->id,
        locationId: $fx->location->id,
        assignedSellerId: $fx->user->id,
        masterId: 0,
        items: [[
            'type' => 'product',
            'product_id' => $fx->product->id,
            'qty' => 1,
            'price' => 1000,
            'warehouse_id' => $fx->warehouse->id,
        ]],
        scenario: 'without_installation',
    ), $fx->user->id);

    // Поиск возможного дубля по телефону
    $candidates = Customer::query()->withoutGlobalScopes()
        ->where('tenant_id', $fx->tenant->id)
        ->where('phone', $fx->customer->phone)
        ->where('id', '!=', $fx->customer->id)
        ->get();

    expect($candidates)->toHaveCount(1);

    $merge = app(CustomerMergeService::class)->merge(
        $fx->tenant->id,
        $fx->customer->id,
        $duplicate->id,
        $fx->user->id,
        'Подтверждённый дубль по телефону',
    );

    expect($merge)->toBeInstanceOf(CustomerMerge::class);
    expect($merge->primary_customer_id)->toBe($fx->customer->id);
    expect($merge->merged_customer_id)->toBe($duplicate->id);
    expect($merge->merged_by)->toBe($fx->user->id);
    expect($merge->transferred['orders'])->toBe(1);
    expect($merge->transferred['vehicles'])->toBe(1);

    expect(
        Order::query()->withoutGlobalScopes()->whereKey($order->id)->value('customer_id')
    )->toBe($fx->customer->id);

    expect(
        Vehicle::query()->withoutGlobalScopes()->whereKey($dupVehicle->id)->value('customer_id')
    )->toBe($fx->customer->id);

    $log = AuditLog::query()->withoutGlobalScopes()
        ->where('tenant_id', $fx->tenant->id)
        ->where('action', 'customer.merged')
        ->first();

    expect($log)->not->toBeNull();
    expect($log->reason)->toBe('Подтверждённый дубль по телефону');
});
