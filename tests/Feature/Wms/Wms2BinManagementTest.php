<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

use Autometria\Models\Wms\WarehouseBin;
use Autometria\Services\Wms\BinAssignmentService;
use Autometria\Services\Wms\WmsLabelPrinterService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\AcceptanceFixture;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->fx = AcceptanceFixture::make('wms2-'.uniqid());
    set_current_tenant_id($this->fx->tenant->id);
    actingAs($this->fx->user);
});

it('migrates warehouse_bins with FORCE RLS and tenant_isolation policy', function (): void {
    expect(Schema::hasTable('warehouse_bins'))->toBeTrue();
    expect(Schema::hasColumn('stock_batches', 'quantity'))->toBeTrue();
    expect(Schema::hasColumn('stock_batches', 'expiration_date'))->toBeTrue();
    expect(Schema::hasColumn('stock_batches', 'warehouse_bin_id'))->toBeTrue();

    if (DB::getDriverName() !== 'pgsql') {
        return;
    }

    $row = DB::selectOne(
        "SELECT c.relforcerowsecurity AS force_rls,
                (SELECT count(*) FROM pg_policies p
                  WHERE p.schemaname = current_schema()
                    AND p.tablename = 'warehouse_bins'
                    AND p.policyname LIKE 'tenant_isolation_%') AS policy_count
         FROM pg_class c
         JOIN pg_namespace n ON n.oid = c.relnamespace
         WHERE n.nspname = current_schema() AND c.relname = 'warehouse_bins'"
    );

    expect($row)->not->toBeNull();
    $force = ($row->force_rls === 't' || $row->force_rls === true || $row->force_rls === 1);
    expect($force)->toBeTrue();
    expect((int) $row->policy_count)->toBeGreaterThan(0);
});

it('suggestBinForReceiving returns a fitting RECEIVING bin', function (): void {
    WarehouseBin::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $this->fx->tenant->id,
        'warehouse_id' => $this->fx->warehouse->id,
        'code' => 'A-01-02-B',
        'zone' => WarehouseBin::ZONE_RECEIVING,
        'max_weight_kg' => '500.000',
        'is_active' => true,
    ]);

    $bin = app(BinAssignmentService::class)
        ->suggestBinForReceiving((int) $this->fx->warehouse->id, '10.000');

    expect($bin)->not->toBeNull();
    expect($bin->code)->toBe('A-01-02-B');
});

it('generates ZPL and TSPL labels for bins', function (): void {
    $bin = new WarehouseBin([
        'code' => 'B-02-01-A',
        'zone' => WarehouseBin::ZONE_PICKING,
    ]);

    $printer = app(WmsLabelPrinterService::class);
    expect($printer->binLabelZpl($bin))->toContain('^XA')->toContain('B-02-01-A');
    expect($printer->binLabelTspl($bin))->toContain('PRINT 1')->toContain('B-02-01-A');
});
