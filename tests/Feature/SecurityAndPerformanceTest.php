<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

use Autometria\Models\Customer;
use Autometria\Models\FiscalReceipt;
use Autometria\Models\LoyaltyTransaction;
use Autometria\Models\Order;
use Autometria\Models\ProductService;
use Autometria\Models\Stock;
use Autometria\Services\Analytics\AnalyticsCacheService;
use Autometria\Services\Analytics\AnalyticsReportService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
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

    $this->fx = AcceptanceFixture::make('secperf-'.uniqid());
    set_current_tenant_id($this->fx->tenant->id);
    actingAs($this->fx->user);
});

it('blocks cross-tenant RLS access to loyalty and fiscal receipts', function (): void {
    $customer = Customer::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $this->fx->tenant->id,
        'type' => Customer::TYPE_INDIVIDUAL,
        'name' => 'Owner A',
        'legal_name' => 'Owner A',
        'phone' => '+7900'.substr((string) hrtime(true), -7),
    ]);

    LoyaltyTransaction::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $this->fx->tenant->id,
        'customer_id' => $customer->id,
        'type' => 'EARN',
        'amount' => 50,
        'balance_after' => 50,
    ]);

    FiscalReceipt::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $this->fx->tenant->id,
        'operation' => 'sell',
        'status' => 'fiscalized',
        'idempotency_key' => 'sec-'.uniqid(),
        'driver_request_id' => (string) \Illuminate\Support\Str::uuid(),
        'total_amount' => 100,
        'payload_snapshot' => ['items' => []],
    ]);

    $attacker = AcceptanceFixture::make('secperf-atk-'.uniqid());
    set_current_tenant_id($attacker->tenant->id);
    actingAs($attacker->user);

    // Eloquent global scope + API must hide victim rows.
    expect(LoyaltyTransaction::query()->count())->toBe(0);
    expect(FiscalReceipt::query()->count())->toBe(0);

    getJson('/api/v1/customers')->assertOk();
    $ids = collect(getJson('/api/v1/customers')->json('data') ?? [])->pluck('id');
    expect($ids->contains($customer->id))->toBeFalse();

    // Catalog hermeticity: FORCE RLS + tenant_isolation_* policies present.
    if (DB::getDriverName() === 'pgsql') {
        foreach (['loyalty_transactions', 'fiscal_receipts', 'stock_batches'] as $table) {
            $meta = DB::selectOne(
                'SELECT c.relrowsecurity AS rls, c.relforcerowsecurity AS force_rls
                 FROM pg_class c
                 JOIN pg_namespace n ON n.oid = c.relnamespace
                 WHERE n.nspname = current_schema() AND c.relname = ?',
                [$table],
            );
            expect($meta)->not->toBeNull();
            expect((bool) $meta->rls)->toBeTrue();
            expect((bool) $meta->force_rls)->toBeTrue();

            $policy = DB::selectOne(
                'SELECT 1 AS ok FROM pg_policies WHERE schemaname = current_schema() AND tablename = ? AND policyname = ?',
                [$table, 'tenant_isolation_'.$table],
            );
            expect($policy)->not->toBeNull();
        }

        // Session GUC for attacker must not match victim tenant_id.
        $guc = DB::selectOne("SELECT current_setting('app.current_tenant_id', true) AS tid");
        expect((int) ($guc->tid ?? 0))->toBe((int) $attacker->tenant->id);
        expect((int) ($guc->tid ?? 0))->not->toBe((int) $this->fx->tenant->id);
    }
});

it('caches analytics summary and invalidates after tenant bump', function (): void {
    $cache = app(AnalyticsCacheService::class);
    $reports = app(AnalyticsReportService::class);

    $first = $cache->getDashboardSummary($this->fx->tenant->id, null, null, null);
    $second = $cache->getDashboardSummary($this->fx->tenant->id, null, null, null);
    expect($second)->toBe($first);

    // Seed a paid order then invalidate — cached payload must refresh.
    Order::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $this->fx->tenant->id,
        'location_id' => $this->fx->location->id,
        'number' => 'AN-'.uniqid(),
        'status' => 'completed',
        'payment_status' => 'paid',
        'total' => 9999,
        'scenario' => 'without_installation',
        'created_by' => $this->fx->user->id,
    ]);

    $cache->invalidateTenant($this->fx->tenant->id);
    $fresh = $cache->getDashboardSummary($this->fx->tenant->id, null, null, null);
    $direct = $reports->getDashboardSummary($this->fx->tenant->id, null, null, null);

    expect($fresh['gross_sales'])->toBe($direct['gross_sales']);
    expect($fresh)->toHaveKeys(['net_revenue', 'gross_profit']);
});

it('registers pos-api and auth-api rate limiters', function (): void {
    $pos = RateLimiter::limiter('pos-api');
    $auth = RateLimiter::limiter('auth-api');
    expect($pos)->not->toBeNull();
    expect($auth)->not->toBeNull();

    $request = request();
    $request->setUserResolver(fn () => $this->fx->user);

    $posLimits = $pos($request);
    $authLimits = $auth($request);

    $posLimit = is_array($posLimits) ? $posLimits[0] : $posLimits;
    $authLimit = is_array($authLimits) ? $authLimits[0] : $authLimits;

    expect($posLimit)->toBeInstanceOf(Limit::class);
    expect($authLimit)->toBeInstanceOf(Limit::class);
    expect($posLimit->maxAttempts)->toBe(120);
    expect($authLimit->maxAttempts)->toBe(10);
});

it('avoids N+1 when rendering stock registry with eager loads', function (): void {
    // Seed several stock rows with distinct products.
    for ($i = 0; $i < 5; $i++) {
        $p = ProductService::query()->withoutGlobalScopes()->forceCreate([
            'tenant_id' => $this->fx->tenant->id,
            'type' => 'product',
            'name' => 'SKU '.$i,
            'article' => 'N1-'.$i.'-'.uniqid(),
            'base_price' => 100,
            'is_active' => true,
        ]);
        Stock::query()->withoutGlobalScopes()->forceCreate([
            'tenant_id' => $this->fx->tenant->id,
            'warehouse_id' => $this->fx->warehouse->id,
            'product_id' => $p->id,
            'actual' => 10,
            'reserved' => 0,
            'available' => 10,
        ]);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();

    $res = getJson('/api/v1/stock?per_page=50');
    $res->assertOk();

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    $selects = collect($queries)->filter(fn ($q) => str_starts_with(strtolower($q['query']), 'select'));
    // 1 stocks page + 1 products eager + 1 warehouses eager (+ optional count) ≪ 5*N
    expect($selects->count())->toBeLessThan(12);
    expect(count($res->json('data') ?? []))->toBeGreaterThanOrEqual(5);
});

it('avoids N+1 when rendering orders movement registry', function (): void {
    for ($i = 0; $i < 4; $i++) {
        Order::query()->withoutGlobalScopes()->forceCreate([
            'tenant_id' => $this->fx->tenant->id,
            'location_id' => $this->fx->location->id,
            'customer_id' => $this->fx->customer->id,
            'number' => 'MV-'.$i.'-'.uniqid(),
            'status' => 'new',
            'payment_status' => 'unpaid',
            'total' => 100 + $i,
            'scenario' => 'without_installation',
            'created_by' => $this->fx->user->id,
        ]);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();

    $res = getJson('/api/v1/orders');
    $res->assertOk();

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    $selects = collect($queries)->filter(fn ($q) => str_starts_with(strtolower($q['query']), 'select'));
    expect($selects->count())->toBeLessThan(15);
});

it('rate-limits auth login after burst of failures', function (): void {
    $ip = '127.0.0.1';
    // RateLimitAuth keys by raw IP; Laravel throttle uses auth-api:{ip}
    RateLimiter::clear($ip);
    RateLimiter::clear('auth-api:'.$ip);

    $hit429 = false;
    for ($i = 0; $i < 15; $i++) {
        $res = postJson('/api/v1/auth/login', [
            'email' => 'nobody-'.uniqid().'@example.test',
            'password' => 'wrong-password',
        ]);
        if ($res->status() === 429) {
            $hit429 = true;
            break;
        }
    }

    expect($hit429)->toBeTrue();
});
