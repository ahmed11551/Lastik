<?php

declare(strict_types=1);

use App\DTOs\CreateOrderDTO;
use App\Models\AuditLog;
use App\Models\OrderItem;
use App\Models\Price;
use App\Models\Role;
use App\Services\OrderService;
use Tests\Support\AcceptanceFixture;

/**
 * Приёмка 49.10 — скидки и ручное изменение цены.
 */
beforeEach(function (): void {
    $this->fx = AcceptanceFixture::make('49-10-'.uniqid());
});

it('pulls price from price list into order item snapshot', function (): void {
    $fx = $this->fx;

    $retail = Price::query()->withoutGlobalScopes()
        ->where('product_id', $fx->product->id)
        ->value('amount');

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
            'price' => (float) $retail,
            'discount' => 0,
            'warehouse_id' => $fx->warehouse->id,
        ]],
        scenario: 'without_installation',
    ), $fx->user->id);

    $item = $order->orderItems->first();
    expect($item->snapshot['price'])->toEqual(5000.0);
    expect($item->snapshot)->toHaveKey('discount');
});

it('stores catalog price snapshot and ignores client price override', function (): void {
    $fx = $this->fx;

    $permissions = $fx->role->permissions ?? [];
    expect($permissions)->toContain('price.change');
    expect($permissions)->toContain('discount.apply');

    $sellerRole = Role::query()->withoutGlobalScopes()->create([
        'tenant_id' => $fx->tenant->id,
        'name' => 'Seller limited',
        'slug' => 'seller_limited',
        'permissions' => ['orders.create', 'orders.view'],
    ]);

    expect($sellerRole->permissions)->not->toContain('price.change');
    expect($sellerRole->permissions)->not->toContain('discount.apply');

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
            'price' => 4500, // клиентская цена игнорируется
            'discount' => 200,
            'warehouse_id' => $fx->warehouse->id,
        ]],
        scenario: 'without_installation',
    ), $fx->user->id);

    /** @var OrderItem $item */
    $item = $order->orderItems->first();
    expect((float) $item->snapshot['price'])->toBe(5000.0);
    expect((float) $item->snapshot['discount'])->toBe(200.0);

    // изменение карточки товара не трогает snapshot
    $fx->product->update(['base_price' => 9999, 'name' => 'Новое имя']);
    $item->refresh();
    expect((float) $item->snapshot['price'])->toBe(5000.0);
    expect($item->snapshot['name'])->toBe('Шина тест');

    $log = AuditLog::query()->withoutGlobalScopes()
        ->where('tenant_id', $fx->tenant->id)
        ->where('action', 'order.created')
        ->first();

    expect($log)->not->toBeNull();
});

it('hides cost price from seller payload by default', function (): void {
    $fx = $this->fx;

    $price = Price::query()->withoutGlobalScopes()
        ->where('product_id', $fx->product->id)
        ->first();

    $sellerView = [
        'amount' => $price->amount,
        'price' => $price->price,
        // cost_price намеренно не отдаём продавцу без права
    ];

    expect($sellerView)->not->toHaveKey('cost_price');
    expect((float) $price->cost_price)->toBe(3500.0);
});
