<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

use Autometria\DTOs\CreateOrderDTO;
use Autometria\Models\AuditLog;
use Autometria\Models\Location;
use Autometria\Models\Stock;
use Autometria\Models\StockBatch;
use Autometria\Models\Warehouse;
use Autometria\Services\BranchWarehouseService;
use Autometria\Services\OrderService;
use Autometria\Services\PriceResolverService;
use Autometria\Services\StockBatchService;
use Autometria\Services\StockTransferService;
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

    $this->fx = AcceptanceFixture::make('mw-'.uniqid());
    set_current_tenant_id($this->fx->tenant->id);
    actingAs($this->fx->user);

    $this->warehouseB = Warehouse::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $this->fx->tenant->id,
        'name' => 'Склад B',
        'external_id' => 'WH-B-'.uniqid(),
        'location_id' => Location::query()->forceCreate([
            'tenant_id' => $this->fx->tenant->id,
            'name' => 'Лок B',
        ])->id,
    ]);

    // Normalize fixture stock for FIFO batch seeding.
    Stock::query()->withoutGlobalScopes()
        ->whereKey($this->fx->stock->id)
        ->update(['actual' => 0, 'reserved' => 0, 'available' => 0]);
    $this->fx->stock->refresh();

    $this->batches = app(StockBatchService::class);
    $this->transfers = app(StockTransferService::class);
    $this->branches = app(BranchWarehouseService::class);
});

it('transfers between warehouses with fifo lots and audit log', function (): void {
    $old = $this->batches->ingress(
        $this->fx->tenant->id,
        $this->fx->warehouse->id,
        $this->fx->product->id,
        8,
        100,
        'OLD',
    );
    $old->update(['received_at' => now()->subDay()]);
    $this->batches->ingress(
        $this->fx->tenant->id,
        $this->fx->warehouse->id,
        $this->fx->product->id,
        8,
        200,
        'NEW',
    );

    $transfer = $this->transfers->transfer(
        $this->fx->tenant->id,
        $this->fx->product->id,
        $this->fx->warehouse->id,
        $this->warehouseB->id,
        10.0,
        'Межскладское FIFO',
        $this->fx->user->id,
    );

    expect((float) $transfer->qty)->toBe(10.0);

    $from = Stock::query()->withoutGlobalScopes()
        ->where('tenant_id', $this->fx->tenant->id)
        ->where('warehouse_id', $this->fx->warehouse->id)
        ->where('product_id', $this->fx->product->id)
        ->first();
    $to = Stock::query()->withoutGlobalScopes()
        ->where('tenant_id', $this->fx->tenant->id)
        ->where('warehouse_id', $this->warehouseB->id)
        ->where('product_id', $this->fx->product->id)
        ->first();

    expect((float) $from->actual)->toBe(6.0);
    expect((float) $to->actual)->toBe(10.0);

    $moved = StockBatch::query()->withoutGlobalScopes()
        ->where('tenant_id', $this->fx->tenant->id)
        ->where('warehouse_id', $this->warehouseB->id)
        ->where('product_id', $this->fx->product->id)
        ->orderBy('cost_price')
        ->get();

    expect($moved)->toHaveCount(2);
    expect((float) $moved[0]->remaining_qty)->toBe(8.0);
    expect((float) $moved[0]->cost_price)->toBe(100.0);
    expect((float) $moved[1]->remaining_qty)->toBe(2.0);
    expect((float) $moved[1]->cost_price)->toBe(200.0);

    expect(
        AuditLog::query()->withoutGlobalScopes()
            ->where('tenant_id', $this->fx->tenant->id)
            ->where('action', 'stock.transferred')
            ->exists()
    )->toBeTrue();

    expect(
        AuditLog::query()->withoutGlobalScopes()
            ->where('tenant_id', $this->fx->tenant->id)
            ->where('action', 'stock.batch.transferred')
            ->exists()
    )->toBeTrue();
});

it('applies warehouse branch price over base in resolver and orders', function (): void {
    expect(app(PriceResolverService::class)->resolve(
        $this->fx->tenant->id,
        $this->fx->product->id,
        $this->fx->warehouse->id,
    ))->toBe(5000.0);

    $this->branches->upsertWarehousePrices($this->fx->tenant->id, $this->fx->warehouse->id, [
        ['product_id' => $this->fx->product->id, 'price' => 4200],
    ]);

    expect(app(PriceResolverService::class)->resolve(
        $this->fx->tenant->id,
        $this->fx->product->id,
        $this->fx->warehouse->id,
    ))->toBe(4200.0);

    // Restock for order reservation
    $this->batches->ingress(
        $this->fx->tenant->id,
        $this->fx->warehouse->id,
        $this->fx->product->id,
        5,
        50,
    );

    $order = app(OrderService::class)->create(new CreateOrderDTO(
        tenantId: $this->fx->tenant->id,
        customerId: $this->fx->customer->id,
        locationId: $this->fx->location->id,
        assignedSellerId: $this->fx->user->id,
        masterId: 0,
        items: [[
            'type' => 'product',
            'product_id' => $this->fx->product->id,
            'qty' => 1,
            'price' => 99999, // client price must be ignored
            'warehouse_id' => $this->fx->warehouse->id,
        ]],
        scenario: 'without_installation',
    ), $this->fx->user->id);

    $item = $order->orderItems->first();
    expect((float) $item->price)->toBe(4200.0);
    expect((float) $order->total)->toBe(4200.0);

    getJson('/api/v1/inventory/resolve-price?product_id='.$this->fx->product->id.'&warehouse_id='.$this->fx->warehouse->id)
        ->assertOk()
        ->assertJsonPath('data.price', 4200);

    postJson('/api/v1/stock/transfers', [
        'product_id' => $this->fx->product->id,
        'from_warehouse_id' => $this->fx->warehouse->id,
        'to_warehouse_id' => $this->warehouseB->id,
        'qty' => 1,
        'reason' => 'API transfer check',
    ])->assertCreated();
});
