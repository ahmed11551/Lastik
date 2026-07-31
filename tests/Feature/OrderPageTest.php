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
use Tests\Support\AcceptanceFixture;

it('creates an order through the implemented order service', function (): void {
    $fx = AcceptanceFixture::make('order-page-'.uniqid());
    $order = app(OrderService::class)->create(new CreateOrderDTO($fx->tenant->id, $fx->customer->id, $fx->location->id, $fx->user->id, $fx->master->id, [[
        'type' => 'service', 'product_id' => $fx->service->id, 'qty' => 1, 'price' => 1200,
    ]]), $fx->user->id);
    expect($order->number)->toStartWith('ORD-')->and($order->orderItems)->toHaveCount(1);
});
