<?php

/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author Себиев Ахмед Сулейманович
 * @license Proprietary & Confidential.
 */

declare(strict_types=1);

use Autometria\DTOs\CreateOrderDTO;
use Autometria\Models\KpiRule;
use Autometria\Services\OrderService;
use Autometria\Services\ServicesService;
use Tests\Support\AcceptanceFixture;

it('calculates service prices with radius modifiers', function (): void {
    $fx = AcceptanceFixture::make('service-price-'.uniqid());
    $fx->service->update(['base_price' => 1000, 'radius_modifier' => ['R16' => 300]]);
    expect(app(ServicesService::class)->calculateServicePrice($fx->service, 'R16'))->toBe(1300.0);
});

it('snapshots service KPI at order creation', function (): void {
    $fx = AcceptanceFixture::make('service-kpi-'.uniqid());
    $order = app(OrderService::class)->create(new CreateOrderDTO($fx->tenant->id, $fx->customer->id, $fx->location->id, $fx->user->id, $fx->master->id, [[
        'type' => 'service', 'product_id' => $fx->service->id, 'qty' => 1, 'price' => 1200,
    ]]), $fx->user->id);
    $item = $order->orderItems->first();
    KpiRule::query()->withoutGlobalScopes()->where('tenant_id', $fx->tenant->id)->where('product_id', $fx->service->id)->update(['percent' => 99]);
    $item->refresh();
    expect((float) $item->kpi_percent)->toBe(15.0);
    expect((float) $item->snapshot['kpi_percent'])->toBe(15.0);
});
