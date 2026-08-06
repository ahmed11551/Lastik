<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

use Autometria\Jobs\CalculateReorderPointJob;
use Autometria\Models\InventoryReorderRecommendation;
use Autometria\Models\Stock;
use Autometria\Models\StockBatch;
use Autometria\Models\StockLotDeduction;
use Autometria\Models\Tenant;
use Autometria\Services\Inventory\InventoryDemandPredictor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AcceptanceFixture;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

beforeEach(function (): void {
    $this->fx = AcceptanceFixture::make('reorder-'.uniqid());
    set_current_tenant_id($this->fx->tenant->id);
    actingAs($this->fx->user);
});

it('computes ROP with BCMath strings only (no float decision path)', function (): void {
    $svc = app(InventoryDemandPredictor::class);

    // totalSold=30 over 30 days → d_avg=1.000 (season factor applied separately)
    // force season by using computeRop with season=1.000
    $r = $svc->computeRop(
        totalSold: '30.000',
        lookbackDays: 30,
        leadTimeDays: 7,
        demandVariance: '0.000',
        onHand: '5.000',
        seasonFactor: '1.000',
    );

    expect($r['d_avg'])->toBe('1.000');
    expect($r['safety_stock'])->toBe('0.000');
    // ROP = 1*7 + 0 = 7
    expect($r['rop'])->toBe('7.000');
    // suggested = max(7-5,0) = 2
    expect($r['suggested_qty'])->toBe('2.000');

    // Non-zero variance → SS > 0
    $r2 = $svc->computeRop('30.000', 30, 7, '4.000', '0.000', '1.000');
    expect($r2['safety_stock'])->not->toBe('0.000');
    expect(bccomp($r2['rop'], $r2['d_avg'], 3))->toBe(1);
});

it('flags dead stock when on_hand > 0 and no recent sales', function (): void {
    $batch = StockBatch::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $this->fx->tenant->id,
        'warehouse_id' => $this->fx->warehouse->id,
        'product_id' => $this->fx->product->id,
        'batch_number' => 'DEAD-1',
        'qty' => '10.000',
        'remaining_qty' => '10.000',
        'cost_price' => '100.00',
        'received_at' => now()->subDays(200),
    ]);

    StockLotDeduction::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $this->fx->tenant->id,
        'stock_batch_id' => $batch->id,
        'warehouse_id' => $this->fx->warehouse->id,
        'product_id' => $this->fx->product->id,
        'quantity' => '1.000',
        'unit_cost' => '100.00',
        'total_cost' => '100.00',
        'refunded_qty' => '0',
        'deducted_at' => now()->subDays(120),
    ]);

    Stock::query()->withoutGlobalScopes()
        ->whereKey($this->fx->stock->id)
        ->update(['available' => '15.000', 'actual' => '15.000']);

    $rows = app(InventoryDemandPredictor::class)->predict(
        (int) $this->fx->tenant->id,
        (int) $this->fx->warehouse->id,
        lookbackDays: 30,
        leadTimeDays: 7,
        deadStockDays: 90,
    );

    $hit = collect($rows)->firstWhere('product_id', $this->fx->product->id);
    expect($hit)->not->toBeNull();
    expect($hit['is_dead_stock'])->toBeTrue();
});

it('dispatches CalculateReorderPointJob onto inventory-reorder queue', function (): void {
    Queue::fake();

    CalculateReorderPointJob::dispatch(
        (int) $this->fx->tenant->id,
        (int) $this->fx->warehouse->id,
    );

    Queue::assertPushedOn('inventory-reorder', CalculateReorderPointJob::class);
});

it('persists recommendations via job handle and exposes API', function (): void {
    $batch = StockBatch::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $this->fx->tenant->id,
        'warehouse_id' => $this->fx->warehouse->id,
        'product_id' => $this->fx->product->id,
        'batch_number' => 'ROP-1',
        'qty' => '50.000',
        'remaining_qty' => '40.000',
        'cost_price' => '50.00',
        'received_at' => now()->subDays(10),
    ]);

    foreach ([2, 5, 8, 12, 15, 20, 22, 25] as $daysAgo) {
        StockLotDeduction::query()->withoutGlobalScopes()->forceCreate([
            'tenant_id' => $this->fx->tenant->id,
            'stock_batch_id' => $batch->id,
            'warehouse_id' => $this->fx->warehouse->id,
            'product_id' => $this->fx->product->id,
            'quantity' => '3.000',
            'unit_cost' => '50.00',
            'total_cost' => '150.00',
            'refunded_qty' => '0',
            'deducted_at' => now()->subDays($daysAgo),
        ]);
    }

    Stock::query()->withoutGlobalScopes()
        ->whereKey($this->fx->stock->id)
        ->update(['available' => '2.000', 'actual' => '2.000']);

    $job = new CalculateReorderPointJob(
        (int) $this->fx->tenant->id,
        (int) $this->fx->warehouse->id,
        30,
        7,
        90,
    );
    $result = $job->handle(app(InventoryDemandPredictor::class));
    expect($result['upserted'])->toBeGreaterThan(0);

    $rec = InventoryReorderRecommendation::query()
        ->where('product_id', $this->fx->product->id)
        ->where('warehouse_id', $this->fx->warehouse->id)
        ->first();

    expect($rec)->not->toBeNull();
    expect(bccomp((string) $rec->rop, '0', 3))->toBe(1);

    $list = getJson('/api/v1/inventory/reorder-recommendations?warehouse_id='.$this->fx->warehouse->id);
    $list->assertOk();
    expect($list->json('data'))->not->toBeEmpty();

    Queue::fake();
    $queued = postJson('/api/v1/inventory/reorder-recommendations/recalculate', [
        'warehouse_id' => $this->fx->warehouse->id,
    ]);
    $queued->assertStatus(202);
    Queue::assertPushed(CalculateReorderPointJob::class);
});

it('has FORCE RLS + tenant_isolation policy on inventory_reorder_recommendations', function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL only');
    }

    $row = DB::selectOne(
        "SELECT c.relrowsecurity AS rls,
                c.relforcerowsecurity AS force_rls,
                (SELECT COUNT(*) FROM pg_policies p
                  WHERE p.schemaname = 'public'
                    AND p.tablename = 'inventory_reorder_recommendations'
                    AND p.policyname LIKE 'tenant_isolation_%') AS policy_count
         FROM pg_class c
         JOIN pg_namespace n ON n.oid = c.relnamespace
         WHERE n.nspname = 'public' AND c.relname = 'inventory_reorder_recommendations'"
    );

    expect($row)->not->toBeNull();
    expect((bool) $row->rls)->toBeTrue();
    expect((bool) $row->force_rls)->toBeTrue();
    expect((int) $row->policy_count)->toBeGreaterThan(0);

    // Cross-tenant isolation via model + set_current_tenant_id
    $other = Tenant::create(['name' => 'ROP-B'.uniqid(), 'slug' => 'rop-b-'.uniqid()]);

    InventoryReorderRecommendation::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $this->fx->tenant->id,
        'warehouse_id' => $this->fx->warehouse->id,
        'product_id' => $this->fx->product->id,
        'd_avg' => '1.000',
        'safety_stock' => '0.500',
        'rop' => '7.500',
        'on_hand' => '2.000',
        'suggested_qty' => '5.500',
        'is_dead_stock' => false,
        'severity' => 'critical',
        'lead_time_days' => 7,
        'lookback_days' => 30,
        'calculated_at' => now(),
    ]);

    set_current_tenant_id($other->id);
    expect(InventoryReorderRecommendation::query()->count())->toBe(0);

    set_current_tenant_id($this->fx->tenant->id);
    expect(InventoryReorderRecommendation::query()->count())->toBeGreaterThan(0);
});
