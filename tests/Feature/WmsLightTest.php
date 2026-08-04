<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

use Autometria\Models\ProductService;
use Autometria\Models\SerialNumber;
use Autometria\Models\StockBatchCell;
use Autometria\Models\StorageCell;
use Autometria\Services\StockBatchService;
use Autometria\Services\Wms\SerialNumberService;
use Autometria\Services\Wms\StorageCellService;
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

    $this->fx = AcceptanceFixture::make('wms-'.uniqid());
    set_current_tenant_id($this->fx->tenant->id);
    actingAs($this->fx->user);

    $this->cells = app(StorageCellService::class);
    $this->serials = app(SerialNumberService::class);

    $this->product = ProductService::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $this->fx->tenant->id,
        'type' => 'product',
        'name' => 'Деталь WMS',
        'article' => 'WMS-'.uniqid(),
        'base_price' => 100,
        'is_active' => true,
    ]);

    $this->batch = app(StockBatchService::class)->ingress(
        $this->fx->tenant->id,
        $this->fx->warehouse->id,
        $this->product->id,
        10.0,
        25.0,
        'WMS-BATCH-'.$this->product->id,
    );
});

it('creates storage cell via service and API', function (): void {
    $cell = $this->cells->create($this->fx->tenant->id, [
        'warehouse_id' => $this->fx->warehouse->id,
        'code' => 'A-01-01',
        'zone' => 'A',
        'rack' => '01',
        'shelf' => '01',
    ], $this->fx->user->id);

    expect($cell->code)->toBe('A-01-01');
    expect(StorageCell::query()->withoutGlobalScopes()->whereKey($cell->id)->exists())->toBeTrue();

    postJson('/api/v1/wms/storage-cells', [
        'warehouse_id' => $this->fx->warehouse->id,
        'code' => 'B-02-02',
        'zone' => 'B',
    ])->assertCreated()->assertJsonPath('data.code', 'B-02-02');

    getJson('/api/v1/wms/storage-cells?warehouse_id='.$this->fx->warehouse->id)
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('places and moves batch between cells', function (): void {
    $from = $this->cells->create($this->fx->tenant->id, [
        'warehouse_id' => $this->fx->warehouse->id,
        'code' => 'FROM-1',
    ]);
    $to = $this->cells->create($this->fx->tenant->id, [
        'warehouse_id' => $this->fx->warehouse->id,
        'code' => 'TO-1',
    ]);

    $placed = $this->cells->placeBatch(
        $this->fx->tenant->id,
        (int) $this->batch->id,
        (int) $from->id,
        4.0,
        $this->fx->user->id,
    );
    expect((float) $placed->quantity)->toBe(4.0);

    postJson('/api/v1/wms/batch-placement', [
        'stock_batch_id' => $this->batch->id,
        'storage_cell_id' => $from->id,
        'qty' => 2,
    ])->assertCreated();

    $this->cells->moveBatch(
        $this->fx->tenant->id,
        (int) $this->batch->id,
        (int) $from->id,
        (int) $to->id,
        3.0,
        $this->fx->user->id,
    );

    $fromQty = (float) (StockBatchCell::query()->withoutGlobalScopes()
        ->where('stock_batch_id', $this->batch->id)
        ->where('storage_cell_id', $from->id)
        ->value('quantity') ?? 0);
    $toQty = (float) (StockBatchCell::query()->withoutGlobalScopes()
        ->where('stock_batch_id', $this->batch->id)
        ->where('storage_cell_id', $to->id)
        ->value('quantity') ?? 0);

    expect($fromQty)->toBe(3.0); // 4+2-3
    expect($toQty)->toBe(3.0);
});

it('registers serial numbers and marks them sold', function (): void {
    $created = $this->serials->receive(
        $this->fx->tenant->id,
        (int) $this->product->id,
        (int) $this->batch->id,
        ['SN-001', 'SN-002'],
        $this->fx->warehouse->id,
        $this->fx->user->id,
    );

    expect($created)->toHaveCount(2);
    expect($created[0]->status)->toBe(SerialNumber::STATUS_IN_STOCK);

    postJson('/api/v1/wms/serial-numbers', [
        'product_id' => $this->product->id,
        'stock_batch_id' => $this->batch->id,
        'serials' => ['SN-003'],
    ])->assertCreated();

    $updated = $this->serials->markSold($this->fx->tenant->id, ['SN-001', 'SN-003'], $this->fx->user->id);
    expect($updated)->toBe(2);

    expect(
        SerialNumber::query()->withoutGlobalScopes()
            ->where('tenant_id', $this->fx->tenant->id)
            ->where('serial', 'SN-001')
            ->value('status')
    )->toBe(SerialNumber::STATUS_SOLD);

    getJson('/api/v1/wms/serial-numbers?status=IN_STOCK')
        ->assertOk()
        ->assertJsonPath('data.0.serial', 'SN-002');
});

it('rejects placement beyond unplaced batch remaining', function (): void {
    $cell = $this->cells->create($this->fx->tenant->id, [
        'warehouse_id' => $this->fx->warehouse->id,
        'code' => 'OVER',
    ]);

    expect(fn () => $this->cells->placeBatch(
        $this->fx->tenant->id,
        (int) $this->batch->id,
        (int) $cell->id,
        11.0,
    ))->toThrow(InvalidArgumentException::class);
});
