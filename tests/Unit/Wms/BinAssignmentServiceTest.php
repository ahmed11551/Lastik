<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

use Autometria\Models\Wms\StockBatch;
use Autometria\Models\Wms\WarehouseBin;
use Autometria\Services\Wms\BinAssignmentService;
use Tests\Support\AcceptanceFixture;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->fx = AcceptanceFixture::make('bin-assign-'.uniqid());
    set_current_tenant_id($this->fx->tenant->id);
    actingAs($this->fx->user);
});

it('suggests RECEIVING bin before STORAGE for receiving', function (): void {
    WarehouseBin::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $this->fx->tenant->id,
        'warehouse_id' => $this->fx->warehouse->id,
        'code' => 'S-01-01-A',
        'zone' => WarehouseBin::ZONE_STORAGE,
        'max_weight_kg' => '1000.000',
        'is_active' => true,
    ]);

    $receiving = WarehouseBin::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $this->fx->tenant->id,
        'warehouse_id' => $this->fx->warehouse->id,
        'code' => 'R-01-01-A',
        'zone' => WarehouseBin::ZONE_RECEIVING,
        'max_weight_kg' => '500.000',
        'is_active' => true,
    ]);

    $svc = app(BinAssignmentService::class);
    $bin = $svc->suggestBinForReceiving((int) $this->fx->warehouse->id, '12.500');

    expect($bin)->not->toBeNull();
    expect((int) $bin->id)->toBe((int) $receiving->id);
    expect($bin->zone)->toBe(WarehouseBin::ZONE_RECEIVING);
});

it('deducts stock from bins by FEFO then FIFO with BCMath precision', function (): void {
    $bin = WarehouseBin::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $this->fx->tenant->id,
        'warehouse_id' => $this->fx->warehouse->id,
        'code' => 'P-01-01-A',
        'zone' => WarehouseBin::ZONE_PICKING,
        'is_active' => true,
    ]);

    $later = StockBatch::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $this->fx->tenant->id,
        'warehouse_id' => $this->fx->warehouse->id,
        'warehouse_bin_id' => $bin->id,
        'product_id' => $this->fx->product->id,
        'batch_number' => 'LATER',
        'quantity' => '5.000',
        'qty' => '5.000',
        'remaining_qty' => '5.000',
        'cost_price' => '10.00',
        'expiration_date' => now()->addDays(30)->toDateString(),
        'received_at' => now()->subDay(),
    ]);

    $earlierExp = StockBatch::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $this->fx->tenant->id,
        'warehouse_id' => $this->fx->warehouse->id,
        'warehouse_bin_id' => $bin->id,
        'product_id' => $this->fx->product->id,
        'batch_number' => 'SOON',
        'quantity' => '4.250',
        'qty' => '4.250',
        'remaining_qty' => '4.250',
        'cost_price' => '10.00',
        'expiration_date' => now()->addDays(5)->toDateString(),
        'received_at' => now()->subDays(2),
    ]);

    $svc = app(BinAssignmentService::class);
    $deductions = $svc->deductStockFromBins((int) $this->fx->product->id, '6.000');

    expect($deductions)->toHaveCount(2);
    expect($deductions[0]['batch_id'])->toBe((int) $earlierExp->id);
    expect($deductions[0]['deducted_qty'])->toBe('4.250');
    expect($deductions[0]['bin_id'])->toBe((int) $bin->id);
    expect($deductions[1]['batch_id'])->toBe((int) $later->id);
    expect($deductions[1]['deducted_qty'])->toBe('1.750');

    $earlierExp->refresh();
    $later->refresh();
    expect((string) $earlierExp->quantity)->toBe('0.000');
    expect((string) $later->quantity)->toBe('3.250');
});

it('throws RuntimeException with exact available qty when bin stock is insufficient', function (): void {
    $bin = WarehouseBin::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $this->fx->tenant->id,
        'warehouse_id' => $this->fx->warehouse->id,
        'code' => 'P-02-01-A',
        'zone' => WarehouseBin::ZONE_STORAGE,
        'is_active' => true,
    ]);

    StockBatch::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $this->fx->tenant->id,
        'warehouse_id' => $this->fx->warehouse->id,
        'warehouse_bin_id' => $bin->id,
        'product_id' => $this->fx->product->id,
        'batch_number' => 'SHORT',
        'quantity' => '2.000',
        'qty' => '2.000',
        'remaining_qty' => '2.000',
        'cost_price' => '1.00',
        'expiration_date' => null,
        'received_at' => now(),
    ]);

    $svc = app(BinAssignmentService::class);

    expect(fn () => $svc->deductStockFromBins((int) $this->fx->product->id, '5.000'))
        ->toThrow(RuntimeException::class, 'available 2.000');
});
