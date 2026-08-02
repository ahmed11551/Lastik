<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

use Autometria\Enums\InventoryDocumentStatusEnum;
use Autometria\Models\AuditLog;
use Autometria\Models\Location;
use Autometria\Models\Stock;
use Autometria\Models\StockBatch;
use Autometria\Models\Warehouse;
use Autometria\Services\StockBatchService;
use Autometria\Services\StockDocumentService;
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

    $this->fx = AcceptanceFixture::make('inv-'.uniqid());
    set_current_tenant_id($this->fx->tenant->id);
    actingAs($this->fx->user);

    $this->warehouseB = Warehouse::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $this->fx->tenant->id,
        'name' => 'Склад B',
        'external_id' => 'WH-B-'.uniqid(),
        'location_id' => Location::query()->forceCreate([
            'tenant_id' => $this->fx->tenant->id,
            'name' => 'Локация B',
        ])->id,
    ]);

    $this->svc = app(StockDocumentService::class);
    $this->batches = app(StockBatchService::class);

    // AcceptanceFixture seeds Stock.actual=20 without batches — normalize for FIFO docs.
    Stock::query()->withoutGlobalScopes()
        ->whereKey($this->fx->stock->id)
        ->update([
            'actual' => 0,
            'reserved' => 0,
            'available' => 0,
        ]);
    $this->fx->stock->refresh();
});

it('creates draft receipt via api and lists it', function (): void {
    $res = postJson('/api/v1/inventory/documents', [
        'type' => 'RECEIPT',
        'from_warehouse_id' => $this->fx->warehouse->id,
        'items' => [
            [
                'product_id' => $this->fx->product->id,
                'qty' => 10,
                'price' => 120,
                'sku' => 'SKU-1',
                'name' => 'Tyre',
            ],
        ],
    ]);

    $res->assertCreated()
        ->assertJsonPath('data.status', 'DRAFT')
        ->assertJsonPath('data.type', 'RECEIPT');

    $list = getJson('/api/v1/inventory/documents?type=RECEIPT&status=DRAFT');
    $list->assertOk();
    expect($list->json('data'))->toHaveCount(1);
});

it('posts incoming receipt creating fifo stock batches', function (): void {
    $doc = $this->svc->createDraft(
        $this->fx->tenant->id,
        'RECEIPT',
        $this->fx->warehouse->id,
        null,
        [['product_id' => $this->fx->product->id, 'qty' => 5, 'price' => 80]],
        $this->fx->user->id,
    );

    $posted = $this->svc->post($this->fx->tenant->id, (int) $doc->id, $this->fx->user->id);
    expect($posted->status)->toBe(InventoryDocumentStatusEnum::POSTED->value);

    $batch = StockBatch::query()->withoutGlobalScopes()
        ->where('tenant_id', $this->fx->tenant->id)
        ->where('warehouse_id', $this->fx->warehouse->id)
        ->where('product_id', $this->fx->product->id)
        ->first();

    expect($batch)->not->toBeNull();
    expect((float) $batch->remaining_qty)->toBe(5.0);
    expect((float) $batch->cost_price)->toBe(80.0);

    $stock = Stock::query()->withoutGlobalScopes()
        ->where('tenant_id', $this->fx->tenant->id)
        ->where('warehouse_id', $this->fx->warehouse->id)
        ->where('product_id', $this->fx->product->id)
        ->first();
    expect((float) $stock->actual)->toBe(5.0);
});

it('posts write-off using fifo writeOff', function (): void {
    $this->batches->ingress($this->fx->tenant->id, $this->fx->warehouse->id, $this->fx->product->id, 10, 50);

    $doc = $this->svc->createDraft(
        $this->fx->tenant->id,
        'WRITE_OFF',
        $this->fx->warehouse->id,
        null,
        [['product_id' => $this->fx->product->id, 'qty' => 4, 'price' => 0]],
        $this->fx->user->id,
    );

    $this->svc->post($this->fx->tenant->id, (int) $doc->id, $this->fx->user->id);

    $stock = Stock::query()->withoutGlobalScopes()
        ->where('tenant_id', $this->fx->tenant->id)
        ->where('warehouse_id', $this->fx->warehouse->id)
        ->where('product_id', $this->fx->product->id)
        ->first();
    expect((float) $stock->actual)->toBe(6.0);
});

it('atomically transfers fifo lots between warehouses', function (): void {
    $old = $this->batches->ingress($this->fx->tenant->id, $this->fx->warehouse->id, $this->fx->product->id, 8, 100, 'OLD');
    $old->update(['received_at' => now()->subDay()]);
    $this->batches->ingress($this->fx->tenant->id, $this->fx->warehouse->id, $this->fx->product->id, 8, 200, 'NEW');

    $doc = $this->svc->createDraft(
        $this->fx->tenant->id,
        'TRANSFER',
        $this->fx->warehouse->id,
        $this->warehouseB->id,
        [['product_id' => $this->fx->product->id, 'qty' => 10, 'price' => 0]],
        $this->fx->user->id,
    );

    postJson('/api/v1/inventory/documents/'.$doc->id.'/post')->assertOk()
        ->assertJsonPath('data.status', 'POSTED');

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

    // FIFO: 8@100 + 2@200 moved to B
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
});

it('posts inventory audit adjusting by counted qty', function (): void {
    $this->batches->ingress($this->fx->tenant->id, $this->fx->warehouse->id, $this->fx->product->id, 10, 70);

    $doc = $this->svc->createDraft(
        $this->fx->tenant->id,
        'INVENTORY',
        $this->fx->warehouse->id,
        null,
        [['product_id' => $this->fx->product->id, 'qty' => 7, 'price' => 70]],
        $this->fx->user->id,
    );

    $this->svc->post($this->fx->tenant->id, (int) $doc->id, $this->fx->user->id);

    $stock = Stock::query()->withoutGlobalScopes()
        ->where('tenant_id', $this->fx->tenant->id)
        ->where('warehouse_id', $this->fx->warehouse->id)
        ->where('product_id', $this->fx->product->id)
        ->first();
    expect((float) $stock->actual)->toBe(7.0);
});

it('rejects posting write-off when stock is insufficient (atomicity)', function (): void {
    $this->batches->ingress($this->fx->tenant->id, $this->fx->warehouse->id, $this->fx->product->id, 2, 40);

    $doc = $this->svc->createDraft(
        $this->fx->tenant->id,
        'WRITE_OFF',
        $this->fx->warehouse->id,
        null,
        [['product_id' => $this->fx->product->id, 'qty' => 9, 'price' => 0]],
        $this->fx->user->id,
    );

    postJson('/api/v1/inventory/documents/'.$doc->id.'/post')
        ->assertStatus(422);

    $doc->refresh();
    expect($doc->status)->toBe(InventoryDocumentStatusEnum::DRAFT->value);

    $stock = Stock::query()->withoutGlobalScopes()
        ->where('tenant_id', $this->fx->tenant->id)
        ->where('warehouse_id', $this->fx->warehouse->id)
        ->where('product_id', $this->fx->product->id)
        ->first();
    expect((float) $stock->actual)->toBe(2.0);
});

it('isolates inventory documents by tenant (rls)', function (): void {
    $this->svc->createDraft(
        $this->fx->tenant->id,
        'RECEIPT',
        $this->fx->warehouse->id,
        null,
        [['product_id' => $this->fx->product->id, 'qty' => 1, 'price' => 10]],
        $this->fx->user->id,
    );

    $other = AcceptanceFixture::make('inv2-'.uniqid());
    set_current_tenant_id($other->tenant->id);
    actingAs($other->user);

    $list = getJson('/api/v1/inventory/documents');
    $list->assertOk();
    expect($list->json('data'))->toHaveCount(0);

    // Foreign warehouse must be rejected
    postJson('/api/v1/inventory/documents', [
        'type' => 'RECEIPT',
        'from_warehouse_id' => $this->fx->warehouse->id,
        'items' => [['product_id' => $other->product->id, 'qty' => 1, 'price' => 1]],
    ])->assertStatus(422);
});

it('accepts backend type aliases INCOMING/WRITEOFF/AUDIT', function (): void {
    $doc = $this->svc->createDraft(
        $this->fx->tenant->id,
        'INCOMING',
        $this->fx->warehouse->id,
        null,
        [['product_id' => $this->fx->product->id, 'qty' => 3, 'price' => 15]],
        $this->fx->user->id,
    );
    expect($doc->type)->toBe('RECEIPT');
});

it('writes AuditLog on create and post', function (): void {
    $doc = $this->svc->createDraft(
        $this->fx->tenant->id,
        'RECEIPT',
        $this->fx->warehouse->id,
        null,
        [['product_id' => $this->fx->product->id, 'qty' => 2, 'price' => 40]],
        $this->fx->user->id,
    );

    expect(
        AuditLog::query()->withoutGlobalScopes()
            ->where('tenant_id', $this->fx->tenant->id)
            ->where('action', 'inventory.document.created')
            ->where('object_id', $doc->id)
            ->exists()
    )->toBeTrue();

    $this->svc->post($this->fx->tenant->id, (int) $doc->id, $this->fx->user->id);

    expect(
        AuditLog::query()->withoutGlobalScopes()
            ->where('tenant_id', $this->fx->tenant->id)
            ->where('action', 'inventory.document.posted')
            ->where('object_id', $doc->id)
            ->exists()
    )->toBeTrue();
});
