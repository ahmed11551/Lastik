<?php

/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author Себиев Ахмед Сулейманович
 * @license Proprietary & Confidential.
 */

declare(strict_types=1);

use Autometria\DTOs\CreateOrderDTO;
use Autometria\Services\Cash\CashShiftService;
use Autometria\Services\OrderService;
use Autometria\Services\PaymentService;
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
