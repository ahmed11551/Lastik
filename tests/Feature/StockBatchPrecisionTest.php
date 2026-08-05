<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

use Autometria\Models\ProductService;
use Autometria\Models\Stock;
use Autometria\Models\StockBatch;
use Autometria\Services\StockBatchService;
use Tests\Support\AcceptanceFixture;
use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->fx = AcceptanceFixture::make('batch-prc-'.uniqid());
    set_current_tenant_id($this->fx->tenant->id);
    actingAs($this->fx->user);

    // Isolated SKU — fixture product already has seeded stock/batches.
    $this->product = ProductService::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $this->fx->tenant->id,
        'type' => ProductService::TYPE_PRODUCT,
        'article' => 'PRC-'.uniqid(),
        'name' => 'Precision Batch SKU',
        'unit' => 'шт',
        'is_active' => true,
        'base_price' => 10,
    ]);
});

it('FIFO write-off cost is exact under float-hostile unit prices', function (): void {
    $svc = app(StockBatchService::class);
    $t = $this->fx->tenant->id;
    $w = $this->fx->warehouse->id;
    $p = (int) $this->product->id;

    // 0.1 × cost 10.0 — classic float trap; cost must stay exact.
    $svc->ingress($t, $w, $p, 0.3, 10.0, 'B-PRC');
    $result = $svc->writeOff($t, $w, $p, 0.1);

    expect($result['written_off'])->toBe(0.1);
    expect($result['cost'])->toBe(1.0);

    $batch = StockBatch::query()->withoutGlobalScopes()
        ->where('tenant_id', $t)
        ->where('batch_number', 'B-PRC')
        ->firstOrFail();
    expect((float) $batch->remaining_qty)->toBe(0.2);

    $stock = Stock::query()->withoutGlobalScopes()
        ->where('tenant_id', $t)
        ->where('warehouse_id', $w)
        ->where('product_id', $p)
        ->firstOrFail();
    expect((float) $stock->actual)->toBe(0.2);
});

it('accumulates many tiny ingress+write-off cycles without qty drift', function (): void {
    $svc = app(StockBatchService::class);
    $t = $this->fx->tenant->id;
    $w = $this->fx->warehouse->id;
    $p = (int) $this->product->id;

    $svc->ingress($t, $w, $p, 1.0, 1.0, 'B-TINY');

    for ($i = 0; $i < 100; $i++) {
        $svc->writeOff($t, $w, $p, 0.01);
    }

    $stock = Stock::query()->withoutGlobalScopes()
        ->where('tenant_id', $t)
        ->where('warehouse_id', $w)
        ->where('product_id', $p)
        ->firstOrFail();

    expect((float) $stock->actual)->toBe(0.0);
});
