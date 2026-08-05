<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

use Autometria\Models\Customer;
use Autometria\Models\ProductService;
use Autometria\Models\Warehouse;
use Tests\Support\AcceptanceFixture;
use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

beforeEach(function (): void {
    $this->withoutMiddleware([
        \Autometria\Http\Middleware\EnsurePermission::class,
        \Autometria\Http\Middleware\EnforceLocationAccess::class,
    ]);
});

it('rejects POS checkout customer_id from another tenant', function (): void {
    $a = AcceptanceFixture::make('pos-idor-a-'.uniqid());
    $b = AcceptanceFixture::make('pos-idor-b-'.uniqid());
    set_current_tenant_id($a->tenant->id);
    actingAs($a->user);

    $foreignCustomer = Customer::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $b->tenant->id,
        'name' => 'Foreign CRM',
        'phone' => '+7900'.random_int(1000000, 9999999),
    ]);

    $res = postJson('/api/v1/pos/checkout', [
        'method' => 'cash',
        'amount_tendered' => 1000,
        'customer_id' => $foreignCustomer->id,
        'items' => [[
            'product_id' => $a->product->id,
            'qty' => 1,
            'warehouse_id' => $a->warehouse->id,
            'type' => 'product',
        ]],
    ]);

    $res->assertStatus(422);
    expect($res->json('errors.customer_id') ?? $res->json('message'))->not->toBeNull();
});

it('rejects POS checkout warehouse_id from another tenant', function (): void {
    $a = AcceptanceFixture::make('pos-idor-w-a-'.uniqid());
    $b = AcceptanceFixture::make('pos-idor-w-b-'.uniqid());
    set_current_tenant_id($a->tenant->id);
    actingAs($a->user);

    $foreignWh = Warehouse::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $b->tenant->id,
        'location_id' => $b->location->id,
        'name' => 'Foreign WH',
    ]);

    $res = postJson('/api/v1/pos/checkout', [
        'method' => 'cash',
        'amount_tendered' => 1000,
        'items' => [[
            'product_id' => $a->product->id,
            'qty' => 1,
            'warehouse_id' => $foreignWh->id,
            'type' => 'product',
        ]],
    ]);

    $res->assertStatus(422);
});

it('rejects POS checkout product_id from another tenant', function (): void {
    $a = AcceptanceFixture::make('pos-idor-p-a-'.uniqid());
    $b = AcceptanceFixture::make('pos-idor-p-b-'.uniqid());
    set_current_tenant_id($a->tenant->id);
    actingAs($a->user);

    $foreignProduct = ProductService::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $b->tenant->id,
        'type' => ProductService::TYPE_PRODUCT,
        'article' => 'X-'.uniqid(),
        'name' => 'Foreign SKU',
        'unit' => 'шт',
        'is_active' => true,
        'base_price' => 10,
    ]);

    $res = postJson('/api/v1/pos/checkout', [
        'method' => 'cash',
        'amount_tendered' => 1000,
        'items' => [[
            'product_id' => $foreignProduct->id,
            'qty' => 1,
            'warehouse_id' => $a->warehouse->id,
            'type' => 'product',
        ]],
    ]);

    $res->assertStatus(422);
});
