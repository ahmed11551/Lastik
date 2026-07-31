<?php

declare(strict_types=1);

use App\DTOs\CreateOrderDTO;

it('creates create order dto from auth context, ignoring payload tenant/location', function (): void {
    $payload = [
        'tenant_id' => 999,
        'customer_id' => 2,
        'location_id' => 888,
        'assigned_seller_id' => 4,
        'master_id' => 5,
        'items' => [
            ['type' => 'product', 'product_id' => 10, 'qty' => 2, 'price' => 1500],
        ],
        'note' => 'test',
    ];

    $dto = CreateOrderDTO::fromRequest($payload, authTenantId: 1, authLocationId: 3);

    expect($dto->tenantId)->toBe(1);
    expect($dto->locationId)->toBe(3);
    expect($dto->customerId)->toBe(2);
    expect($dto->note)->toBe('test');
    expect($dto->items)->toHaveCount(1);
    expect($dto->items[0]['qty'])->toBe(2);
});
