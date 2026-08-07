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
use Autometria\Models\StockLotDeduction;
use Autometria\Models\Supplier;
use Autometria\Models\Tenant;
use Autometria\Models\Warehouse;
use Autometria\Services\Analytics\DemandForecasterService;
use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->tenant = Tenant::query()->forceCreate([
        'name' => 'Forecast Tenant',
        'slug' => 'forecast-tenant',
        'timezone' => 'Europe/Moscow',
        'is_active' => true,
    ]);
    $this->tenantId = $this->tenant->id;

    $this->warehouse = Warehouse::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $this->tenantId,
        'name' => 'Склад F',
        'external_id' => 'WH-F',
        'location_id' => \Autometria\Models\Location::query()->forceCreate([
            'tenant_id' => $this->tenantId,
            'name' => 'Локация F',
        ])->id,
    ]);

    $user = \Autometria\Models\User::query()->forceCreate([
        'tenant_id' => $this->tenantId,
        'name' => 'Forecast User',
        'email' => 'forecast@example.com',
        'password_hash' => bcrypt('secret'),
    ]);
    actingAs($user);
});

/**
 * Test 1: ROP = (avg_daily_sales * lead_time) + safety_stock; is_stockout_risk при низком остатке.
 */
it('computes reorder point and stockout risk', function (): void {
    $product = ProductService::query()->forceCreate([
        'tenant_id' => $this->tenantId,
        'type' => 'product',
        'name' => 'Прогноз Товар',
        'article' => 'FC-1',
        'base_price' => 100.0,
        'lead_time_days' => 10,
        'safety_stock' => 50.0,
        'is_active' => true,
    ]);

    // Продажи: 300 шт за 90 дней -> avg_daily_sales = 300/90 = 3.33
    $batch = StockBatch::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $this->tenantId,
        'product_id' => $product->id,
        'warehouse_id' => $this->warehouse->id,
        'quantity' => 1000.0,
        'cost_price' => 50.0,
        'received_at' => now()->subMonths(4),
    ]);
    for ($i = 0; $i < 3; $i++) {
        StockLotDeduction::query()->withoutGlobalScopes()->forceCreate([
            'tenant_id' => $this->tenantId,
            'product_id' => $product->id,
            'stock_batch_id' => $batch->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 100.0,
            'unit_cost' => 50.0,
            'deducted_at' => now()->subDays(10 + $i * 20),
        ]);
    }

    // Текущий остаток = 30 (ниже ROP)
    Stock::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $this->tenantId,
        'product_id' => $product->id,
        'warehouse_id' => $this->warehouse->id,
        'actual' => 30.0,
        'reserved' => 0.0,
    ]);

    $service = new DemandForecasterService();
    $f = $service->forecast($this->tenantId, $product->id, 90);

    // ROP = 3.33*10 + 50 = 83.3; current 30 <= 83.3 -> risk
    expect($f['avg_daily_sales'])->toBeGreaterThan(3.0);
    expect($f['reorder_point'])->toBeGreaterThan(80.0);
    expect($f['current_stock'])->toBe(30.0);
    expect($f['is_stockout_risk'])->toBeTrue();
});

/**
 * Test 2: товар с достаточным остатком не в зоне риска.
 */
it('does not flag healthy stock as risk', function (): void {
    $product = ProductService::query()->forceCreate([
        'tenant_id' => $this->tenantId,
        'type' => 'product',
        'name' => 'Здоровый Товар',
        'article' => 'FC-2',
        'base_price' => 100.0,
        'lead_time_days' => 5,
        'safety_stock' => 10.0,
        'is_active' => true,
    ]);

    Stock::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $this->tenantId,
        'product_id' => $product->id,
        'warehouse_id' => $this->warehouse->id,
        'actual' => 500.0,
        'reserved' => 0.0,
    ]);

    $service = new DemandForecasterService();
    $f = $service->forecast($this->tenantId, $product->id, 90);

    expect($f['is_stockout_risk'])->toBeFalse();
});

/**
 * Test 3: riskScan возвращает только товары в зоне риска.
 */
it('riskScan returns only at-risk products', function (): void {
    $riskProduct = ProductService::query()->forceCreate([
        'tenant_id' => $this->tenantId,
        'type' => 'product',
        'name' => 'Риск',
        'article' => 'FC-R',
        'base_price' => 100.0,
        'lead_time_days' => 10,
        'safety_stock' => 50.0,
        'is_active' => true,
    ]);
    Stock::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $this->tenantId,
        'product_id' => $riskProduct->id,
        'warehouse_id' => $this->warehouse->id,
        'actual' => 5.0,
        'reserved' => 0.0,
    ]);

    $okProduct = ProductService::query()->forceCreate([
        'tenant_id' => $this->tenantId,
        'type' => 'product',
        'name' => 'Ок',
        'article' => 'FC-OK',
        'base_price' => 100.0,
        'lead_time_days' => 5,
        'safety_stock' => 10.0,
        'is_active' => true,
    ]);
    Stock::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $this->tenantId,
        'product_id' => $okProduct->id,
        'warehouse_id' => $this->warehouse->id,
        'actual' => 500.0,
        'reserved' => 0.0,
    ]);

    $service = new DemandForecasterService();
    $risks = $service->riskScan($this->tenantId, 90);

    expect($risks)->toHaveCount(1);
    expect($risks->first()['product_id'])->toBe($riskProduct->id);
});
