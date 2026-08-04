<?php

/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author Себиев Ахмед Сулейманович
 * @license Proprietary & Confidential.
 */

declare(strict_types=1);

use Autometria\Models\Stock;
use Autometria\Services\StockBatchService;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $tenant = \Autometria\Models\Tenant::query()->withoutGlobalScopes()->forceCreate([
        'name' => 'QTY-Tenant',
        'slug' => 'qty-'.uniqid(),
        'timezone' => 'Europe/Moscow',
        'is_active' => true,
    ]);
    set_current_tenant_id($tenant->id);
    $this->tenant = $tenant;

    $location = \Autometria\Models\Location::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $tenant->id,
        'name' => 'Loc-Q',
        'address' => 'addr',
        'timezone' => 'Europe/Moscow',
        'is_active' => true,
    ]);

    $this->warehouse = \Autometria\Models\Warehouse::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $tenant->id,
        'name' => 'WH-Q',
        'location_id' => $location->id,
    ]);
    $this->product = \Autometria\Models\ProductService::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $tenant->id,
        'type' => 'product',
        'name' => 'Масло 5W40',
        'unit' => 'l',
        'base_price' => 800,
        'is_active' => true,
    ]);
});

it('stores fractional stock quantity (1.5 l) without rounding', function (): void {
    $stock = Stock::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => 1,
        'warehouse_id' => $this->warehouse->id,
        'product_id' => $this->product->id,
        'actual' => 10.000,
        'reserved' => 0.000,
        'available' => 10.000,
        'quantity' => 1.5,
    ]);

    $read = Stock::query()->withoutGlobalScopes()->find($stock->id);
    expect((float) $read->quantity)->toBe(1.5);
    expect($read->quantity)->toBe('1.50'); // decimal:2 сохраняет точность
});

it('write-off of 1.5 units succeeds and updates quantity precisely', function (): void {
    $batches = app(StockBatchService::class);
    $batches->ingress($this->tenant->id, $this->warehouse->id, $this->product->id, 5.0, 800.0, 'BATCH-Q1');

    // Ставим quantity = 5.0, списываем 1.5 → остаток 3.5
    DB::table('stocks')->where('tenant_id', $this->tenant->id)->where('product_id', $this->product->id)
        ->update(['quantity' => 5.0]);

    $result = $batches->writeOff($this->tenant->id, $this->warehouse->id, $this->product->id, 1.5);

    expect((float) $result['written_off'])->toBe(1.5);
    expect((float) $result['cost'])->toBe(1200.0); // 1.5 * 800

    $stock = Stock::query()->withoutGlobalScopes()
        ->where('tenant_id', $this->tenant->id)
        ->where('product_id', $this->product->id)
        ->first();
    expect((float) $stock->quantity)->toBe(3.5);
});
