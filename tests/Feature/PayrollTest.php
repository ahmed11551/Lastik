<?php

declare(strict_types=1);

use Autometria\Http\Middleware\EnforceLocationAccess;
use Autometria\Http\Middleware\EnsurePermission;
use Autometria\Models\AccrualRule;
use Autometria\Models\AuditLog;
use Autometria\Models\Deduction;
use Autometria\Models\Earning;
use Autometria\Models\PayrollPeriod;
use Autometria\Services\Payroll\PayrollService;
use Tests\Support\AcceptanceFixture;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

beforeEach(function (): void {
    $this->withoutMiddleware([
        EnsurePermission::class,
        EnforceLocationAccess::class,
    ]);
    config(['cache.default' => 'array']);

    $this->fx = AcceptanceFixture::make('payroll-'.uniqid());
    set_current_tenant_id($this->fx->tenant->id);
    actingAs($this->fx->user);
    $this->service = app(PayrollService::class);
});

it('calculates approves and pays a payroll period', function (): void {
    Earning::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $this->fx->tenant->id, 'user_id' => $this->fx->user->id,
        'amount' => 1000, 'source' => 'KPI', 'rule_snapshot' => [], 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->service->createAccrualRule($this->fx->tenant->id, ['name' => 'Фиксированная премия', 'type' => AccrualRule::TYPE_FIXED, 'value' => 200, 'is_active' => true]);
    $this->service->createDeduction($this->fx->tenant->id, ['name' => 'Удержание', 'type' => Deduction::TYPE_FIXED, 'value' => 100, 'is_active' => true]);
    $period = $this->service->createPeriod($this->fx->tenant->id, ['name' => 'Август', 'period_from' => now()->startOfMonth()->toDateString(), 'period_to' => now()->endOfMonth()->toDateString()]);

    $period = $this->service->calculate($this->fx->tenant->id, $period->id, $this->fx->user->id);
    $payslip = $period->payslips->firstWhere('user_id', $this->fx->user->id);
    expect($period->status)->toBe(PayrollPeriod::STATUS_CALCULATED);
    expect((float) $payslip->gross)->toBe(1200.0);
    expect((float) $payslip->deductions_total)->toBe(100.0);
    expect((float) $payslip->net)->toBe(1100.0);
    expect(AuditLog::query()->withoutGlobalScopes()->where('tenant_id', $this->fx->tenant->id)->where('action', 'payroll.calculated')->exists())->toBeTrue();

    $period = $this->service->approve($this->fx->tenant->id, $period->id, $this->fx->user->id);
    expect($period->status)->toBe(PayrollPeriod::STATUS_APPROVED);
    $period = $this->service->markPaid($this->fx->tenant->id, $period->id, $this->fx->user->id);
    expect($period->status)->toBe(PayrollPeriod::STATUS_PAID);
    expect($period->paid_at)->not->toBeNull();
    expect(AuditLog::query()->withoutGlobalScopes()->where('tenant_id', $this->fx->tenant->id)->where('action', 'payroll.paid')->exists())->toBeTrue();
});

it('serves payroll API endpoints', function (): void {
    $period = postJson('/api/v1/payroll-periods', ['name' => 'API период', 'period_from' => '2026-08-01', 'period_to' => '2026-08-31'])
        ->assertCreated()->json('data');
    getJson('/api/v1/payroll-periods')->assertOk()->assertJsonPath('data.0.id', $period['id']);
    postJson('/api/v1/accrual-rules', ['name' => 'API фикс', 'type' => 'FIXED', 'value' => 500])->assertCreated();
    postJson('/api/v1/deductions', ['name' => 'API удержание', 'type' => 'FIXED', 'value' => 50])->assertCreated();
    postJson('/api/v1/payroll-periods/'.$period['id'].'/calculate')->assertOk()->assertJsonPath('data.status', 'CALCULATED');
    getJson('/api/v1/payslips?period_id='.$period['id'])->assertOk();
});
