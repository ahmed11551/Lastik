<?php

declare(strict_types=1);

use App\DTOs\CreateOrderDTO;
use App\Services\Cash\CashShiftService;
use App\Services\OrderService;
use App\Services\PaymentService;
use Tests\Support\AcceptanceFixture;

test('payment after a shift closes is rejected by PaymentService', function (): void {
    $fx = AcceptanceFixture::make('closed-payment-'.uniqid());
    $order = app(OrderService::class)->create(new CreateOrderDTO($fx->tenant->id, $fx->customer->id, $fx->location->id, $fx->user->id, $fx->master->id, [[
        'type' => 'product', 'product_id' => $fx->product->id, 'qty' => 1, 'price' => 1000, 'warehouse_id' => $fx->warehouse->id,
    ]]), $fx->user->id);
    app(CashShiftService::class)->close($fx->shift);

    expect(fn () => app(PaymentService::class)->accept($fx->tenant->id, $order->id, [['method' => 'cash', 'amount' => 1000]], $fx->user->id, $fx->shift->id))
        ->toThrow(RuntimeException::class);
});
