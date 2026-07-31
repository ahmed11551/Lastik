<?php

declare(strict_types=1);

use App\DTOs\CreateOrderDTO;
use App\Exceptions\Domain\InsufficientStockException;
use App\Models\Order;
use App\Services\OrderService;
use Tests\Support\AcceptanceFixture;

it('creates an order and reserves its product stock', function (): void {
    $fx = AcceptanceFixture::make('order-store-'.uniqid());
    $order = app(OrderService::class)->create(new CreateOrderDTO($fx->tenant->id, $fx->customer->id, $fx->location->id, $fx->user->id, $fx->master->id, [[
        'type' => 'product', 'product_id' => $fx->product->id, 'qty' => 2, 'price' => 1500, 'warehouse_id' => $fx->warehouse->id,
    ]]), $fx->user->id);

    $fx->stock->refresh();
    expect($order)->toBeInstanceOf(Order::class)->and($order->orderItems)->toHaveCount(1);
    expect((float) $fx->stock->available)->toBe(18.0)->and((float) $fx->stock->reserved)->toBe(2.0);
});

it('rejects insufficient stock without creating an order', function (): void {
    $fx = AcceptanceFixture::make('order-insufficient-'.uniqid());
    $before = Order::query()->withoutGlobalScopes()->count();
    expect(fn () => app(OrderService::class)->create(new CreateOrderDTO($fx->tenant->id, $fx->customer->id, $fx->location->id, $fx->user->id, $fx->master->id, [[
        'type' => 'product', 'product_id' => $fx->product->id, 'qty' => 21, 'price' => 1500, 'warehouse_id' => $fx->warehouse->id,
    ]]), $fx->user->id))->toThrow(InsufficientStockException::class);
    expect(Order::query()->withoutGlobalScopes()->count())->toBe($before);
});
