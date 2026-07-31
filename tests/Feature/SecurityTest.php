<?php

declare(strict_types=1);

use App\Models\Order;
use Tests\Support\AcceptanceFixture;

it('scopes tenant models to the active tenant', function (): void {
    $a = AcceptanceFixture::make('security-a-'.uniqid());
    $b = AcceptanceFixture::make('security-b-'.uniqid());
    Order::query()->withoutGlobalScopes()->create([
        'tenant_id' => $b->tenant->id, 'location_id' => $b->location->id, 'number' => 'SEC-1',
        'status' => 'created', 'payment_status' => 'unpaid', 'total' => 0, 'created_by' => $b->user->id,
    ]);
    app()->instance('current_tenant_id', $a->tenant->id);

    expect(Order::query()->count())->toBe(0);
    expect(Order::query()->withoutGlobalScopes()->where('tenant_id', $b->tenant->id)->count())->toBe(1);
});
