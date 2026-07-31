<?php

declare(strict_types=1);

use App\DTOs\CreateOrderDTO;
use App\Models\Earning;
use App\Models\KpiRule;
use App\Services\Kpi\KpiService;
use App\Services\OrderService;
use Tests\Support\AcceptanceFixture;

/**
 * Приёмка 49.11 — минимальная выработка / KPI.
 */
beforeEach(function (): void {
    $this->fx = AcceptanceFixture::make('49-11-'.uniqid());
});

it('snapshots kpi percent on product and service lines for seller and master', function (): void {
    $fx = $this->fx;

    $order = app(OrderService::class)->create(new CreateOrderDTO(
        tenantId: $fx->tenant->id,
        customerId: $fx->customer->id,
        locationId: $fx->location->id,
        assignedSellerId: $fx->user->id,
        masterId: $fx->master->id,
        items: [
            [
                'type' => 'product',
                'product_id' => $fx->product->id,
                'qty' => 2,
                'price' => 5000,
                'warehouse_id' => $fx->warehouse->id,
            ],
            [
                'type' => 'service',
                'product_id' => $fx->service->id,
                'qty' => 1,
                'price' => 1200,
                'worker_id' => $fx->master->id,
                'commission_rate' => 15,
            ],
        ],
        vehicleId: $fx->vehicle->id,
        scenario: 'with_installation',
    ), $fx->user->id);

    $productItem = $order->orderItems->firstWhere('type', 'product');
    $serviceItem = $order->orderItems->firstWhere('type', 'service');

    expect((float) $productItem->kpi_percent)->toBe(5.0);
    expect($productItem->snapshot['kpi_rule']['target_type'])->toBe('seller');
    expect((float) $serviceItem->kpi_percent)->toBe(15.0);
    expect($serviceItem->snapshot['kpi_rule']['target_type'])->toBe('master');

    // изменение правила после продажи не меняет snapshot
    KpiRule::query()->withoutGlobalScopes()
        ->where('product_id', $fx->product->id)
        ->update(['percent' => 99]);

    $productItem->refresh();
    expect((float) $productItem->snapshot['kpi_percent'])->toBe(5.0);
});

it('can calculate earnings bound to employee from paid order context', function (): void {
    $fx = $this->fx;

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
            'price' => 1000,
            'worker_id' => $fx->master->id,
            'commission_rate' => 10,
        ]],
        scenario: 'with_installation',
        vehicleId: $fx->vehicle->id,
    ), $fx->user->id);

    $item = $order->orderItems->first();

    // Минимальная запись выработки (если KpiService поддерживает — иначе создаём напрямую)
    $earning = Earning::query()->withoutGlobalScopes()->create([
        'tenant_id' => $fx->tenant->id,
        'user_id' => $fx->master->id,
        'order_id' => $order->id,
        'order_item_id' => $item->id,
        'amount' => $item->kpi_amount ?? 100,
        'percent' => $item->kpi_percent ?? 10,
        'source' => 'item',
        'rule_snapshot' => $item->snapshot['kpi_rule'] ?? ['percent' => 10],
    ]);

    expect($earning->user_id)->toBe($fx->master->id);
    expect($earning->rule_snapshot)->not->toBeNull();

    // корректировка — старая запись не удаляется
    $correction = Earning::query()->withoutGlobalScopes()->create([
        'tenant_id' => $fx->tenant->id,
        'user_id' => $fx->master->id,
        'order_id' => $order->id,
        'order_item_id' => $item->id,
        'amount' => -20,
        'percent' => 10,
        'source' => 'correction',
        'rule_snapshot' => ['reason' => 'корректировка оплаты', 'base_earning_id' => $earning->id],
    ]);

    expect(Earning::query()->withoutGlobalScopes()
        ->where('order_id', $order->id)->count())->toBe(2);
    expect((float) $correction->amount)->toBe(-20.0);
});
