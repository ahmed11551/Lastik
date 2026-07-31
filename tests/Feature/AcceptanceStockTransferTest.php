<?php

declare(strict_types=1);

use App\DTOs\CreateOrderDTO;
use App\Exceptions\Domain\InsufficientStockException;
use App\Models\AuditLog;
use App\Models\Stock;
use App\Models\Warehouse;
use App\Services\OrderLifecycleService;
use App\Services\OrderService;
use App\Services\StockTransferService;
use Tests\Support\AcceptanceFixture;

/**
 * Приёмка 49.7 — резерв и базовые перемещения.
 */
beforeEach(function (): void {
    $this->fx = AcceptanceFixture::make('49-7-'.uniqid());
});

it('reserves stock on order and releases on item delete with reason', function (): void {
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
            'qty' => 4,
            'price' => 1000,
            'warehouse_id' => $fx->warehouse->id,
        ]],
        scenario: 'without_installation',
    ), $fx->user->id);

    $stock = Stock::query()->withoutGlobalScopes()->whereKey($fx->stock->id)->first();
    expect((float) $stock->reserved)->toBe(4.0);
    expect((float) $stock->available)->toBe(16.0);

    expect(
        AuditLog::query()->withoutGlobalScopes()
            ->where('tenant_id', $fx->tenant->id)
            ->where('action', 'stock.reserved')
            ->exists()
    )->toBeTrue();

    $item = $order->orderItems->first();

    app(OrderLifecycleService::class)->removeItem(
        $fx->tenant->id,
        $item->id,
        $fx->user->id,
        'Позиция добавлена ошибочно',
    );

    $stock->refresh();
    expect((float) $stock->reserved)->toBe(0.0);
    expect((float) $stock->available)->toBe(20.0);

    expect(
        AuditLog::query()->withoutGlobalScopes()
            ->where('action', 'stock.released')
            ->where('tenant_id', $fx->tenant->id)
            ->exists()
    )->toBeTrue();
});

it('transfers stock between warehouses with reason and audit', function (): void {
    $fx = $this->fx;

    $whB = Warehouse::query()->withoutGlobalScopes()->create([
        'tenant_id' => $fx->tenant->id,
        'location_id' => $fx->location->id,
        'name' => 'Склад Б',
        'location' => 'B',
    ]);

    $transfer = app(StockTransferService::class)->transfer(
        $fx->tenant->id,
        $fx->product->id,
        $fx->warehouse->id,
        $whB->id,
        5.0,
        'Перемещение под заказ филиала',
        $fx->user->id,
    );

    expect((float) $transfer->qty)->toBe(5.0);
    expect($transfer->reason)->toBe('Перемещение под заказ филиала');

    $from = Stock::query()->withoutGlobalScopes()->whereKey($fx->stock->id)->first();
    expect((float) $from->actual)->toBe(15.0);
    expect((float) $from->available)->toBe(15.0);

    $to = Stock::query()->withoutGlobalScopes()
        ->where('warehouse_id', $whB->id)
        ->where('product_id', $fx->product->id)
        ->first();

    expect($to)->not->toBeNull();
    expect((float) $to->actual)->toBe(5.0);
    expect((float) $to->available)->toBe(5.0);

    $log = AuditLog::query()->withoutGlobalScopes()
        ->where('tenant_id', $fx->tenant->id)
        ->where('action', 'stock.transferred')
        ->first();

    expect($log)->not->toBeNull();
    expect($log->reason)->toBe('Перемещение под заказ филиала');
});

it('does not allow transfer exceeding available including reserves', function (): void {
    $fx = $this->fx;

    app(OrderService::class)->create(new CreateOrderDTO(
        tenantId: $fx->tenant->id,
        customerId: $fx->customer->id,
        locationId: $fx->location->id,
        assignedSellerId: $fx->user->id,
        masterId: 0,
        items: [[
            'type' => 'product',
            'product_id' => $fx->product->id,
            'qty' => 15,
            'price' => 1000,
            'warehouse_id' => $fx->warehouse->id,
        ]],
        scenario: 'without_installation',
    ), $fx->user->id);

    $whB = Warehouse::query()->withoutGlobalScopes()->create([
        'tenant_id' => $fx->tenant->id,
        'name' => 'Склад В',
        'location' => 'C',
    ]);

    // available = 5 after reserve 15 of 20
    expect(fn () => app(StockTransferService::class)->transfer(
        $fx->tenant->id,
        $fx->product->id,
        $fx->warehouse->id,
        $whB->id,
        6.0,
        'Слишком много',
        $fx->user->id,
    ))->toThrow(InsufficientStockException::class);
});
