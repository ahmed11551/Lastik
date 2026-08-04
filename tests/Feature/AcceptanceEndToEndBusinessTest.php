<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 *
 * Layer 5 — сквозной бизнес-день (acceptance 49.x condensed loop).
 */

declare(strict_types=1);

use Autometria\DTOs\CreateOrderDTO;
use Autometria\Enums\ShiftStatusEnum;
use Autometria\Models\AuditLog;
use Autometria\Models\Issuance;
use Autometria\Models\Order;
use Autometria\Models\Reservation;
use Autometria\Models\Stock;
use Autometria\Services\Cash\CashShiftService;
use Autometria\Services\IssuanceService;
use Autometria\Services\OrderService;
use Autometria\Services\StockReservationService;
use Illuminate\Support\Facades\DB;
use Tests\Support\AcceptanceFixture;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->withoutMiddleware([
        \Autometria\Http\Middleware\EnsurePermission::class,
        \Autometria\Http\Middleware\EnforceLocationAccess::class,
    ]);
    config(['cache.default' => 'array']);
});

/**
 * E2E: смена → резерв → заказ → выдача → audit_logs (+ RLS SELECT probe).
 */
it('end to end business loop from shift opening to stock deduction and audit verification', function (): void {
    $fx = AcceptanceFixture::make('e2e-49-'.uniqid());
    set_current_tenant_id($fx->tenant->id);
    actingAs($fx->user);

    // === 49.5 / 49.6: открытие кассовой смены ===
    $shift = app(CashShiftService::class)->open(
        $fx->tenant->id,
        $fx->location->id,
        $fx->user->id,
        0.0,
    );
    expect($shift->status)->toBe(ShiftStatusEnum::OPENED->value)
        ->and($shift->closed_at)->toBeNull();

    Stock::query()->withoutGlobalScopes()->whereKey($fx->stock->id)->update([
        'actual' => 10,
        'reserved' => 0,
        'available' => 10,
    ]);

    // === 49.3: явный резерв (Layer 2 lock path) ===
    $manual = app(StockReservationService::class)->reserve(
        (int) $fx->stock->id,
        (int) $fx->tenant->id,
        3.0,
    );
    expect($manual->status)->toBe(Reservation::STATUS_ACTIVE);

    $mid = Stock::query()->withoutGlobalScopes()->whereKey($fx->stock->id)->firstOrFail();
    expect((float) $mid->available)->toBe(7.0)
        ->and((float) $mid->reserved)->toBe(3.0);

    app(StockReservationService::class)->release(
        (int) $fx->stock->id,
        (int) $fx->tenant->id,
        3.0,
    );

    Stock::query()->withoutGlobalScopes()->whereKey($fx->stock->id)->update([
        'actual' => 10,
        'reserved' => 0,
        'available' => 10,
    ]);

    // === 49.3 / 49.10: заказ резервирует товар ===
    $order = app(OrderService::class)->create(new CreateOrderDTO(
        tenantId: $fx->tenant->id,
        customerId: $fx->customer->id,
        locationId: $fx->location->id,
        assignedSellerId: $fx->user->id,
        masterId: $fx->master->id,
        items: [[
            'type' => 'product',
            'product_id' => $fx->product->id,
            'qty' => 3,
            'price' => 100.0,
            'warehouse_id' => $fx->warehouse->id,
        ]],
        scenario: 'without_installation',
        vehicleId: $fx->vehicle->id,
    ), $fx->user->id);

    $afterOrder = Stock::query()->withoutGlobalScopes()->whereKey($fx->stock->id)->firstOrFail();
    expect((float) $afterOrder->available)->toBe(7.0)
        ->and((float) $afterOrder->reserved)->toBe(3.0);

    // === 49.13: выдача / списание ===
    $item = $order->orderItems->firstOrFail();
    $issuance = app(IssuanceService::class)->issue(
        $fx->tenant->id,
        $order->id,
        $item->id,
        3.0,
        $fx->user->id,
        Issuance::BASIS_TO_CUSTOMER,
        'E2E выдача',
    );
    expect($issuance->order_id)->toBe($order->id);

    $final = Stock::query()->withoutGlobalScopes()->whereKey($fx->stock->id)->firstOrFail();
    expect((float) $final->reserved)->toBe(0.0)
        ->and((float) $final->actual)->toBe(7.0)
        ->and((float) $final->available)->toBe(7.0);

    // === 49.8: AuditLog ===
    expect(
        AuditLog::query()->withoutGlobalScopes()
            ->where('tenant_id', $fx->tenant->id)
            ->whereIn('action', ['issuance.created', 'stock.reserved'])
            ->exists()
    )->toBeTrue();

    if (DB::getDriverName() !== 'pgsql') {
        return;
    }

    // Чужой тенант создаём ДО SET ROLE (probe — только SELECT)
    $other = AcceptanceFixture::make('e2e-other-'.uniqid());

    ensureE2eRlsProbeRole();
    DB::beginTransaction();
    try {
        DB::statement('SET LOCAL ROLE lastik_rls_probe');
        set_current_tenant_id($fx->tenant->id);
        expect(Order::query()->withoutGlobalScopes()->whereKey($order->id)->exists())->toBeTrue();

        set_current_tenant_id($other->tenant->id);
        expect(Order::query()->withoutGlobalScopes()->whereKey($order->id)->exists())->toBeFalse();
    } finally {
        DB::rollBack();
        set_current_tenant_id($fx->tenant->id);
    }
});

function ensureE2eRlsProbeRole(): void
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
    DB::statement('GRANT lastik_rls_probe TO lastik');
}
