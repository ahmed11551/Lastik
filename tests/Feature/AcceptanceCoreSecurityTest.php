<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 *
 * Acceptance Layer 1 (DB/RLS) + Layer 2 (core locks) — Pest integration.
 * Эталон Team Lead адаптирован под Autometria (не App\ / StockItem / factories).
 */

declare(strict_types=1);

use Autometria\Exceptions\Domain\InsufficientStockException;
use Autometria\Models\Order;
use Autometria\Models\Stock;
use Autometria\Services\StockBatchService;
use Autometria\Services\StockReservationService;
use Illuminate\Support\Facades\DB;
use Tests\Support\AcceptanceFixture;

beforeEach(function (): void {
    $this->withoutMiddleware([
        \Autometria\Http\Middleware\EnsurePermission::class,
        \Autometria\Http\Middleware\EnforceLocationAccess::class,
    ]);
    config(['cache.default' => 'array']);
});

/**
 * Слой 2 — Race Condition («Два кассира»).
 * Конкурентное списание/резерв последней единицы: lockForUpdate + DB::transaction,
 * available не уходит ниже нуля.
 */
it('two cashiers transaction concurrency protects stock availability from dropping below zero', function (): void {
    $source = (string) file_get_contents(app_path('Services/StockBatchService.php'));
    expect($source)->toContain('DB::transaction')->and($source)->toContain('lockForUpdate');

    $reserveSource = (string) file_get_contents(app_path('Services/StockReservationService.php'));
    expect($reserveSource)->toContain('DB::transaction')->and($reserveSource)->toContain('lockForUpdate');

    $fx = AcceptanceFixture::make('core-race-'.uniqid());
    set_current_tenant_id($fx->tenant->id);

    app(StockBatchService::class)->ingress(
        $fx->tenant->id,
        $fx->warehouse->id,
        $fx->product->id,
        1.0,
        100.0,
        'RACE-'.uniqid(),
        $fx->user->id,
    );

    Stock::query()->withoutGlobalScopes()
        ->whereKey($fx->stock->id)
        ->update([
            'actual' => 1,
            'reserved' => 0,
            'available' => 1,
        ]);

    $reservations = app(StockReservationService::class);

    // Кассир A захватывает последнюю единицу
    $reservations->reserve((int) $fx->stock->id, (int) $fx->tenant->id, 1.0);

    // Кассир B — отказ (аналог nested concurrent write под lock)
    $exceptionCaught = false;
    try {
        $reservations->reserve((int) $fx->stock->id, (int) $fx->tenant->id, 1.0);
    } catch (InsufficientStockException) {
        $exceptionCaught = true;
    }

    $finalStock = $fx->stock->fresh();
    expect($exceptionCaught)->toBeTrue()
        ->and((float) $finalStock->available)->toBeGreaterThanOrEqual(0.0)
        ->and((float) $finalStock->available)->toBe(0.0)
        ->and((float) $finalStock->reserved)->toBe(1.0);
});

/**
 * Слой 1 — RLS: withoutGlobalScopes() не обходит PostgreSQL под lastik_rls_probe
 * (роль без BYPASSRLS; lastik — superuser и иначе видит всё).
 */
it('rls enforces tenant isolation even when eloquent withoutGlobalScopes is triggered', function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL RLS required');
    }

    ensureLastikRlsProbeRole();

    $tenantA = AcceptanceFixture::make('core-rls-a-'.uniqid());
    set_current_tenant_id($tenantA->tenant->id);

    $orderA = Order::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $tenantA->tenant->id,
        'location_id' => $tenantA->location->id,
        'customer_id' => $tenantA->customer->id,
        'number' => 'CORE-RLS-A-'.uniqid(),
        'status' => Order::STATUS_CREATED,
        'payment_status' => 'unpaid',
        'total' => 1500,
        'scenario' => 'without_installation',
        'created_by' => $tenantA->user->id,
    ]);

    $tenantB = AcceptanceFixture::make('core-rls-b-'.uniqid());
    set_current_tenant_id($tenantB->tenant->id);

    $orderB = Order::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $tenantB->tenant->id,
        'location_id' => $tenantB->location->id,
        'customer_id' => $tenantB->customer->id,
        'number' => 'CORE-RLS-B-'.uniqid(),
        'status' => Order::STATUS_CREATED,
        'payment_status' => 'unpaid',
        'total' => 2200,
        'scenario' => 'without_installation',
        'created_by' => $tenantB->user->id,
    ]);

    DB::beginTransaction();
    try {
        DB::statement('SET LOCAL ROLE lastik_rls_probe');

        // Контекст тенанта A (аналог X-Tenant-ID)
        set_current_tenant_id($tenantA->tenant->id);

        $visible = Order::query()->withoutGlobalScopes()
            ->whereIn('id', [$orderA->id, $orderB->id])
            ->get();

        expect($visible)->toHaveCount(1)
            ->and((int) $visible->first()->tenant_id)->toBe((int) $tenantA->tenant->id)
            ->and($visible->contains(fn (Order $o): bool => (int) $o->id === (int) $orderB->id))->toBeFalse();

        // Чужой / пустой контекст — пусто
        set_current_tenant_id($tenantB->tenant->id);
        expect(Order::query()->withoutGlobalScopes()->whereKey($orderA->id)->get())->toHaveCount(0);
        expect(DB::select('SELECT id FROM orders WHERE id = ?', [$orderA->id]))->toBeEmpty();

        DB::statement("SELECT set_config('app.current_tenant_id', '', true)");
        app()->instance('current_tenant_id', null);
        expect(DB::select('SELECT id FROM orders WHERE id = ?', [$orderA->id]))->toBeEmpty();
    } finally {
        DB::rollBack(); // RESET ROLE via end of transaction
    }
});

/**
 * Non-superuser / non-BYPASSRLS role for RLS probes (migrations run as lastik superuser).
 */
function ensureLastikRlsProbeRole(): void
{
    DB::statement(<<<'SQL'
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'lastik_rls_probe') THEN
        CREATE ROLE lastik_rls_probe NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE INHERIT LOGIN PASSWORD 'secret';
    END IF;
END
$$;
SQL);

    DB::statement('GRANT USAGE ON SCHEMA public TO lastik_rls_probe');
    DB::statement('GRANT SELECT ON ALL TABLES IN SCHEMA public TO lastik_rls_probe');
    DB::statement('GRANT SELECT ON ALL SEQUENCES IN SCHEMA public TO lastik_rls_probe');
    DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT ON TABLES TO lastik_rls_probe');
    DB::statement('GRANT lastik_rls_probe TO lastik');
}
