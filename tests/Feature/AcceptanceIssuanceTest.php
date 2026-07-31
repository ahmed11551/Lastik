<?php

declare(strict_types=1);

use App\DTOs\CreateOrderDTO;
use App\Models\AuditLog;
use App\Models\Issuance;
use App\Models\Reservation;
use App\Models\Stock;
use App\Services\IssuanceService;
use App\Services\OrderLifecycleService;
use App\Services\OrderService;
use Tests\Support\AcceptanceFixture;

/**
 * Приёмка 49.8 — выдача товара.
 */
beforeEach(function (): void {
    $this->fx = AcceptanceFixture::make('49-8-'.uniqid());
});

it('issues product against reservation and writes audit', function (): void {
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
            'qty' => 2,
            'price' => 1000,
            'warehouse_id' => $fx->warehouse->id,
        ]],
        scenario: 'without_installation',
    ), $fx->user->id);

    $item = $order->orderItems->first();
    $stockBefore = Stock::query()->withoutGlobalScopes()->whereKey($fx->stock->id)->first();

    expect((float) $stockBefore->reserved)->toBe(2.0);
    expect((float) $stockBefore->available)->toBe(18.0);

    $issuance = app(IssuanceService::class)->issue(
        $fx->tenant->id,
        $order->id,
        $item->id,
        2.0,
        $fx->user->id,
        Issuance::BASIS_TO_CUSTOMER,
        'Выдача покупателю',
    );

    expect($issuance->order_id)->toBe($order->id);
    expect($issuance->order_item_id)->toBe($item->id);
    expect((float) $issuance->qty)->toBe(2.0);
    expect($issuance->issued_by)->toBe($fx->user->id);
    expect($issuance->issued_at)->not->toBeNull();
    expect($issuance->warehouse_id)->toBe($fx->warehouse->id);

    $stock = Stock::query()->withoutGlobalScopes()->whereKey($fx->stock->id)->first();
    expect((float) $stock->actual)->toBe(18.0);
    expect((float) $stock->reserved)->toBe(0.0);
    expect((float) $stock->available)->toBe(18.0);

    $reservation = Reservation::query()->withoutGlobalScopes()
        ->where('order_item_id', $item->id)
        ->first();
    expect($reservation->status)->toBe(Reservation::STATUS_USED);

    $item->refresh();
    expect($item->snapshot['item_status'])->toBe('issued');

    expect(
        AuditLog::query()->withoutGlobalScopes()
            ->where('tenant_id', $fx->tenant->id)
            ->where('action', 'issuance.created')
            ->exists()
    )->toBeTrue();
});

it('blocks direct delete of issued item', function (): void {
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
    ), $fx->user->id);

    $item = $order->orderItems->first();

    app(IssuanceService::class)->issue(
        $fx->tenant->id,
        $order->id,
        $item->id,
        1.0,
        $fx->user->id,
    );

    expect(fn () => app(OrderLifecycleService::class)->removeItem(
        $fx->tenant->id,
        $item->id,
        $fx->user->id,
        'ошибка',
    ))->toThrow(RuntimeException::class);
});

it('releases reservation on order cancel with reason', function (): void {
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
            'qty' => 3,
            'price' => 1000,
            'warehouse_id' => $fx->warehouse->id,
        ]],
        scenario: 'without_installation',
    ), $fx->user->id);

    $cancelled = app(OrderLifecycleService::class)->cancel(
        $fx->tenant->id,
        $order->id,
        $fx->user->id,
        'Клиент отказался',
    );

    expect($cancelled->status)->toBe('cancelled');

    $stock = Stock::query()->withoutGlobalScopes()->whereKey($fx->stock->id)->first();
    expect((float) $stock->reserved)->toBe(0.0);
    expect((float) $stock->available)->toBe(20.0);

    $log = AuditLog::query()->withoutGlobalScopes()
        ->where('action', 'order.cancelled')
        ->where('tenant_id', $fx->tenant->id)
        ->first();

    expect($log->reason)->toBe('Клиент отказался');
});
