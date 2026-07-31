<?php

/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author Себиев Ахмед Сулейманович
 * @license Proprietary & Confidential.
 */

declare(strict_types=1);

use Autometria\DTOs\CreateOrderDTO;
use Autometria\Exceptions\Domain\NoActiveShiftException;
use Autometria\Services\OrderService;
use Tests\Support\AcceptanceFixture;

it('binds order to active shift on successful POST /orders', function (): void {
    $fx = AcceptanceFixture::make('shift-bind-'.uniqid());
    $order = app(OrderService::class)->create(new CreateOrderDTO($fx->tenant->id, $fx->customer->id, $fx->location->id, $fx->user->id, $fx->master->id, [[
        'type' => 'product', 'product_id' => $fx->product->id, 'qty' => 2, 'price' => 1000, 'warehouse_id' => $fx->warehouse->id,
    ]]), $fx->user->id);
    expect($order->shift_id)->toBe($fx->shift->id);
});

it('blocks order creation without active shift', function (): void {
    $fx = AcceptanceFixture::make('no-shift-'.uniqid());
    $fx->shift->update(['status' => 'closed', 'closed_at' => now()]);
    expect(fn () => app(OrderService::class)->create(new CreateOrderDTO($fx->tenant->id, $fx->customer->id, $fx->location->id, $fx->user->id, $fx->master->id, []), $fx->user->id))
        ->toThrow(NoActiveShiftException::class);
});
