<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

use Autometria\Enums\StockReservationStatusEnum;
use Autometria\Models\Branch;
use Autometria\Models\Location;
use Autometria\Models\Price;
use Autometria\Models\Stock;
use Autometria\Models\StockReservation;
use Autometria\Models\Warehouse;
use Autometria\Services\BranchWarehouseService;
use Tests\Support\AcceptanceFixture;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

beforeEach(function (): void {
    $this->withoutMiddleware([
        \Autometria\Http\Middleware\EnsurePermission::class,
        \Autometria\Http\Middleware\EnforceLocationAccess::class,
    ]);
    config(['cache.default' => 'array']);

    $this->fx = AcceptanceFixture::make('br-'.uniqid());
    set_current_tenant_id($this->fx->tenant->id);
    actingAs($this->fx->user);

    $this->svc = app(BranchWarehouseService::class);

    Stock::query()->withoutGlobalScopes()
        ->whereKey($this->fx->stock->id)
        ->update(['actual' => 20, 'reserved' => 0, 'available' => 20]);
    $this->fx->stock->refresh();
});

it('resolves warehouse price cascade over base retail', function (): void {
    // Base retail 5000 from fixture Price
    expect($this->svc->resolveProductPrice(
        $this->fx->tenant->id,
        $this->fx->product->id,
        $this->fx->warehouse->id,
    ))->toBe(5000.0);

    $this->svc->upsertWarehousePrices($this->fx->tenant->id, $this->fx->warehouse->id, [
        ['product_id' => $this->fx->product->id, 'price' => 4200],
    ]);

    expect($this->svc->resolveProductPrice(
        $this->fx->tenant->id,
        $this->fx->product->id,
        $this->fx->warehouse->id,
    ))->toBe(4200.0);
});

it('atomically reserves stock and reduces available', function (): void {
    $res = $this->svc->reserveStock(
        $this->fx->tenant->id,
        $this->fx->warehouse->id,
        $this->fx->product->id,
        5.0,
        30,
        $this->fx->user->id,
    );

    expect($res->status)->toBe(StockReservationStatusEnum::ACTIVE->value);

    $this->fx->stock->refresh();
    expect((float) $this->fx->stock->reserved)->toBe(5.0);
    expect((float) $this->fx->stock->available)->toBe(15.0);

    postJson('/api/v1/inventory/reservations', [
        'warehouse_id' => $this->fx->warehouse->id,
        'product_id' => $this->fx->product->id,
        'quantity' => 100,
        'ttl_minutes' => 10,
    ])->assertStatus(422);
});

it('releases expired reservations and restores available', function (): void {
    $res = $this->svc->reserveStock(
        $this->fx->tenant->id,
        $this->fx->warehouse->id,
        $this->fx->product->id,
        4.0,
        1,
        $this->fx->user->id,
    );

    StockReservation::query()->withoutGlobalScopes()
        ->whereKey($res->id)
        ->update(['reserved_until' => now()->subMinute()]);

    $count = $this->svc->releaseExpiredReservations($this->fx->tenant->id);
    expect($count)->toBe(1);

    $res->refresh();
    expect($res->status)->toBe(StockReservationStatusEnum::RELEASED->value);

    $this->fx->stock->refresh();
    expect((float) $this->fx->stock->reserved)->toBe(0.0);
    expect((float) $this->fx->stock->available)->toBe(20.0);
});

it('creates branches and returns consolidated stock via api', function (): void {
    $branch = $this->svc->createBranch(
        $this->fx->tenant->id,
        'Филиал Центр',
        'CTR',
        'ул. Тест 1',
        $this->fx->warehouse->id,
    );

    Warehouse::query()->withoutGlobalScopes()
        ->whereKey($this->fx->warehouse->id)
        ->update(['branch_id' => $branch->id]);

    $whB = Warehouse::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $this->fx->tenant->id,
        'name' => 'Склад B',
        'external_id' => 'WH-B-'.uniqid(),
        'location_id' => Location::query()->forceCreate([
            'tenant_id' => $this->fx->tenant->id,
            'name' => 'Лок B',
        ])->id,
        'branch_id' => $branch->id,
    ]);

    Stock::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $this->fx->tenant->id,
        'warehouse_id' => $whB->id,
        'product_id' => $this->fx->product->id,
        'actual' => 7,
        'reserved' => 0,
        'available' => 7,
    ]);

    getJson('/api/v1/branches')->assertOk()
        ->assertJsonPath('data.0.code', 'CTR');

    $cons = getJson('/api/v1/inventory/consolidated-stock?branch_id='.$branch->id);
    $cons->assertOk();
    expect($cons->json('data'))->toHaveCount(2);

    postJson('/api/v1/inventory/warehouse-prices', [
        'warehouse_id' => $this->fx->warehouse->id,
        'prices' => [['product_id' => $this->fx->product->id, 'price' => 3999]],
    ])->assertOk();

    getJson('/api/v1/inventory/resolve-price?product_id='.$this->fx->product->id.'&warehouse_id='.$this->fx->warehouse->id)
        ->assertOk()
        ->assertJsonPath('data.price', 3999);
});

it('isolates branches by tenant (rls)', function (): void {
    $this->svc->createBranch($this->fx->tenant->id, 'A', 'A1', null, $this->fx->warehouse->id);

    $other = AcceptanceFixture::make('br2-'.uniqid());
    set_current_tenant_id($other->tenant->id);
    actingAs($other->user);

    getJson('/api/v1/branches')->assertOk();
    expect(getJson('/api/v1/branches')->json('data'))->toHaveCount(0);
});

it('binds cash shift to branch and warehouse', function (): void {
    $branch = $this->svc->createBranch(
        $this->fx->tenant->id,
        'POS Branch',
        'POS1',
        null,
        $this->fx->warehouse->id,
    );

    // Close fixture shift if any open
    \Autometria\Models\CashShift::query()->withoutGlobalScopes()
        ->where('tenant_id', $this->fx->tenant->id)
        ->whereNull('closed_at')
        ->update(['closed_at' => now(), 'status' => 'closed']);

    $shift = app(\Autometria\Services\Cash\CashShiftService::class)->open(
        $this->fx->tenant->id,
        $this->fx->location->id,
        $this->fx->user->id,
        100,
        $branch->id,
        $this->fx->warehouse->id,
    );

    expect($shift->branch_id)->toBe($branch->id);
    expect($shift->warehouse_id)->toBe($this->fx->warehouse->id);
});
