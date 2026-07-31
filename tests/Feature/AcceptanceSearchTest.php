<?php

/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author Себиев Ахмед Сулейманович
 * @license Proprietary & Confidential.
 */

declare(strict_types=1);

use Autometria\DTOs\CreateOrderDTO;
use Autometria\Services\OrderService;
use Autometria\Services\SearchService;
use Tests\Support\AcceptanceFixture;

/**
 * Приёмка п.36 — быстрый поиск по ФИО, телефону, госномеру, № заказа.
 */
beforeEach(function (): void {
    $this->fx = AcceptanceFixture::make('search-'.uniqid());
});

it('finds customer by partial FIO', function (): void {
    $fx = $this->fx;
    $token = explode(' ', (string) $fx->customer->name)[0] ?? 'Иван';
    $result = app(SearchService::class)->search($fx->tenant->id, $token);

    expect(collect($result['customers'])->pluck('id')->all())->toContain($fx->customer->id);
});

it('finds customer by phone ignoring spaces and dashes', function (): void {
    $fx = $this->fx;
    $digits = preg_replace('/\D+/', '', (string) $fx->customer->phone);
    $formatted = substr($digits, 0, 4).' '.substr($digits, 4, 3).'-'.substr($digits, 7);

    $result = app(SearchService::class)->search($fx->tenant->id, $formatted);

    expect(collect($result['customers'])->pluck('id')->all())->toContain($fx->customer->id);
});

it('finds vehicle by plate ignoring case and spaces', function (): void {
    $fx = $this->fx;
    $spaced = strtoupper(substr($fx->vehicle->plate, 0, 1).' '.substr($fx->vehicle->plate, 1));

    $result = app(SearchService::class)->search($fx->tenant->id, $spaced);

    expect(collect($result['vehicles'])->pluck('id')->all())->toContain($fx->vehicle->id);
});

it('finds order by number', function (): void {
    $fx = $this->fx;

    $order = app(OrderService::class)->create(new CreateOrderDTO(
        tenantId: $fx->tenant->id,
        customerId: $fx->customer->id,
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
        vehicleId: $fx->vehicle->id,
    ), $fx->user->id);

    $result = app(SearchService::class)->search($fx->tenant->id, (string) $order->number);

    expect(collect($result['orders'])->pluck('id')->all())->toContain($order->id);
});

it('does not return foreign tenant matches', function (): void {
    $fx = $this->fx;
    $other = AcceptanceFixture::make('search-other-'.uniqid());

    $result = app(SearchService::class)->search($fx->tenant->id, (string) $other->customer->name);

    expect(collect($result['customers'])->pluck('id')->all())->not->toContain($other->customer->id);
    expect(collect($result['vehicles'])->pluck('id')->all())->not->toContain($other->vehicle->id);
});
