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
use Tests\Support\AcceptanceFixture;

// 49.5 + 49.6: snapshot позиции заказа неизменен при изменении карточки товара/KPI.
test('order item stores immutable snapshot with price and kpi rule at add time', function (): void {
    $fx = AcceptanceFixture::make('snapshot-'.uniqid());
    $order = app(OrderService::class)->create(new CreateOrderDTO($fx->tenant->id, $fx->customer->id, $fx->location->id, $fx->user->id, $fx->master->id, [[
        'type' => 'product', 'product_id' => $fx->product->id, 'qty' => 1, 'price' => 150, 'warehouse_id' => $fx->warehouse->id,
    ]]), $fx->user->id);
    $item = $order->orderItems->first();
    $snapshot = $item->snapshot;
    KpiRule::query()->withoutGlobalScopes()->where('tenant_id', $fx->tenant->id)->update(['percent' => 99]);
    $fx->product->update(['name' => 'Изменено']);
    $item->refresh();

    expect((float) $item->snapshot['price'])->toBe(5000.0);
    expect($item->snapshot['kpi_rule']['percent'])->toBe($snapshot['kpi_rule']['percent']);
    expect($item->snapshot['name'])->toBe($snapshot['name']);
});
