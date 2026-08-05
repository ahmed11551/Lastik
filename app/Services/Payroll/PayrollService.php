<?php

declare(strict_types=1);

namespace Autometria\Services\Payroll;

use Autometria\Models\AccrualRule;
use Autometria\Models\Deduction;
use Autometria\Models\Earning;
use Autometria\Models\PayrollPeriod;
use Autometria\Models\Payslip;
use Autometria\Models\PayslipItem;
use Autometria\Models\User;
use Autometria\Services\Traits\BcMathDecimal;
use Autometria\Support\AuditLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class PayrollService
{
    use BcMathDecimal;

    public function createPeriod(int $tenantId, array $data, ?int $userId = null): PayrollPeriod
    {
        return DB::transaction(function () use ($tenantId, $data): PayrollPeriod {
            set_current_tenant_id($tenantId);

            return PayrollPeriod::query()->withoutGlobalScopes()->forceCreate([
                'tenant_id' => $tenantId,
                'name' => (string) $data['name'],
                'period_from' => $data['period_from'],
                'period_to' => $data['period_to'],
                'status' => PayrollPeriod::STATUS_DRAFT,
                'total_gross' => 0,
                'total_deductions' => 0,
                'total_net' => 0,
            ]);
        });
    }

    public function calculate(int $tenantId, int $periodId, ?int $userId = null): PayrollPeriod
    {
        return DB::transaction(function () use ($tenantId, $periodId, $userId): PayrollPeriod {
            set_current_tenant_id($tenantId);
            $period = $this->period($tenantId, $periodId, true);
            if ($period->status !== PayrollPeriod::STATUS_DRAFT) {
                throw new InvalidArgumentException('Only DRAFT payroll periods can be calculated');
            }

            $rules = $this->listAccrualRules($tenantId, true);
            $deductions = $this->listDeductions($tenantId, true);
            $totals = ['gross' => 0.0, 'deductions' => 0.0, 'net' => 0.0];
            $from = Carbon::parse($period->period_from)->startOfDay();
            $to = Carbon::parse($period->period_to)->endOfDay();

            User::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->orderBy('id')->each(
                function (User $employee) use ($tenantId, $period, $from, $to, $rules, $deductions, &$totals): void {
                    $earnings = Earning::query()->withoutGlobalScopes()
                        ->where('tenant_id', $tenantId)
                        ->where('user_id', $employee->id)
                        ->whereBetween('created_at', [$from, $to]);
                    $base = $this->bcRound((float) (clone $earnings)->sum('amount'));
                    $bonus = $this->bcRound((float) (clone $earnings)
                        ->whereRaw("LOWER(COALESCE(source, '')) LIKE ?", ['%bonus%'])
                        ->sum('amount'));

                    $payslip = Payslip::query()->withoutGlobalScopes()->forceCreate([
                        'tenant_id' => $tenantId,
                        'payroll_period_id' => $period->id,
                        'user_id' => $employee->id,
                        'gross' => 0,
                        'deductions_total' => 0,
                        'net' => 0,
                        'status' => PayrollPeriod::STATUS_CALCULATED,
                    ]);

                    $gross = 0.0;
                    if ($base > 0) {
                        $this->item($tenantId, $payslip->id, PayslipItem::TYPE_EARNING, 'Выработка (KPI)', $base);
                        $gross = $this->bcAdd($gross, $base);
                    }
                    foreach ($rules as $rule) {
                        $amount = match ($rule->type) {
                            AccrualRule::TYPE_KPI_PERCENT => $this->bcDiv($this->bcMul((string) $base, (string) $rule->value), '100'),
                            AccrualRule::TYPE_FIXED => (float) $rule->value,
                            AccrualRule::TYPE_BONUS => $bonus,
                            default => 0,
                        };
                        $amount = $this->bcRound($amount);
                        if ($amount > 0) {
                            $this->item($tenantId, $payslip->id, PayslipItem::TYPE_EARNING, $rule->name, $amount, $rule->id);
                            $gross = $this->bcAdd($gross, $amount);
                        }
                    }
                    $gross = $this->bcRound($gross);
                    $deductionTotal = 0.0;
                    foreach ($deductions as $deduction) {
                        $amount = $deduction->type === Deduction::TYPE_PERCENT
                            ? $this->bcDiv($this->bcMul((string) $gross, (string) $deduction->value), '100')
                            : (float) $deduction->value;
                        $amount = $this->bcRound($amount);
                        if ($amount > 0) {
                            $this->item($tenantId, $payslip->id, PayslipItem::TYPE_DEDUCTION, $deduction->name, $amount, $deduction->id);
                            $deductionTotal = $this->bcAdd($deductionTotal, $amount);
                        }
                    }
                    $net = $this->bcRound($this->bcSub((string) $gross, (string) $deductionTotal));
                    $payslip->forceFill(['gross' => $gross, 'deductions_total' => $deductionTotal, 'net' => $net])->save();
                    $totals['gross'] = $this->bcAdd($totals['gross'], $gross);
                    $totals['deductions'] = $this->bcAdd($totals['deductions'], $deductionTotal);
                    $totals['net'] = $this->bcAdd($totals['net'], $net);
                }
            );

            $period->forceFill([
                'status' => PayrollPeriod::STATUS_CALCULATED,
                'total_gross' => $this->bcRound($totals['gross']),
                'total_deductions' => $this->bcRound($totals['deductions']),
                'total_net' => $this->bcRound($totals['net']),
            ])->save();
            AuditLog::write($tenantId, $userId ?? auth()->id(), 'payroll.calculated', PayrollPeriod::class, (int) $period->id, [], $period->only(['status', 'total_gross', 'total_deductions', 'total_net']));

            return $period->fresh(['payslips.items', 'payslips.user']) ?? $period;
        });
    }

    public function approve(int $tenantId, int $periodId, ?int $userId = null): PayrollPeriod
    {
        return $this->transition($tenantId, $periodId, PayrollPeriod::STATUS_CALCULATED, PayrollPeriod::STATUS_APPROVED);
    }

    public function markPaid(int $tenantId, int $periodId, ?int $userId = null): PayrollPeriod
    {
        return DB::transaction(function () use ($tenantId, $periodId, $userId): PayrollPeriod {
            set_current_tenant_id($tenantId);
            $period = $this->period($tenantId, $periodId, true);
            if ($period->status !== PayrollPeriod::STATUS_APPROVED) {
                throw new InvalidArgumentException('Only APPROVED payroll periods can be paid');
            }
            $period->forceFill(['status' => PayrollPeriod::STATUS_PAID, 'paid_at' => now()])->save();
            Payslip::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->where('payroll_period_id', $period->id)->update(['status' => PayrollPeriod::STATUS_PAID]);
            AuditLog::write($tenantId, $userId ?? auth()->id(), 'payroll.paid', PayrollPeriod::class, (int) $period->id, ['status' => PayrollPeriod::STATUS_APPROVED], ['status' => PayrollPeriod::STATUS_PAID]);

            return $period->fresh(['payslips.items', 'payslips.user']) ?? $period;
        });
    }

    public function listPeriods(int $tenantId): Collection
    {
        set_current_tenant_id($tenantId);
        return PayrollPeriod::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->latest('period_from')->get();
    }

    public function listPayslips(int $tenantId, ?int $periodId = null): Collection
    {
        set_current_tenant_id($tenantId);
        return Payslip::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)
            ->when($periodId, fn ($q) => $q->where('payroll_period_id', $periodId))
            ->with(['user', 'payrollPeriod'])->latest()->get();
    }

    public function getPayslip(int $tenantId, int $payslipId): Payslip
    {
        set_current_tenant_id($tenantId);
        return Payslip::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->with(['items', 'user', 'payrollPeriod'])->findOrFail($payslipId);
    }

    public function listDeductions(int $tenantId, bool $activeOnly = false): Collection
    {
        set_current_tenant_id($tenantId);
        return Deduction::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->when($activeOnly, fn ($q) => $q->where('is_active', true))->orderBy('name')->get();
    }

    public function createDeduction(int $tenantId, array $data): Deduction
    {
        set_current_tenant_id($tenantId);
        return Deduction::query()->withoutGlobalScopes()->forceCreate(['tenant_id' => $tenantId, ...$data]);
    }

    public function listAccrualRules(int $tenantId, bool $activeOnly = false): Collection
    {
        set_current_tenant_id($tenantId);
        return AccrualRule::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->when($activeOnly, fn ($q) => $q->where('is_active', true))->orderBy('name')->get();
    }

    public function createAccrualRule(int $tenantId, array $data): AccrualRule
    {
        set_current_tenant_id($tenantId);
        return AccrualRule::query()->withoutGlobalScopes()->forceCreate(['tenant_id' => $tenantId, ...$data]);
    }

    private function transition(int $tenantId, int $periodId, string $from, string $to): PayrollPeriod
    {
        return DB::transaction(function () use ($tenantId, $periodId, $from, $to): PayrollPeriod {
            set_current_tenant_id($tenantId);
            $period = $this->period($tenantId, $periodId, true);
            if ($period->status !== $from) {
                throw new InvalidArgumentException("Only {$from} payroll periods can be transitioned");
            }
            $period->forceFill(['status' => $to])->save();
            Payslip::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->where('payroll_period_id', $period->id)->update(['status' => $to]);

            return $period->fresh(['payslips.items', 'payslips.user']) ?? $period;
        });
    }

    private function period(int $tenantId, int $periodId, bool $lock = false): PayrollPeriod
    {
        $query = PayrollPeriod::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->whereKey($periodId);
        return ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
    }

    private function item(int $tenantId, int $payslipId, string $type, string $label, float $amount, ?int $sourceId = null): void
    {
        PayslipItem::query()->withoutGlobalScopes()->forceCreate([
            'tenant_id' => $tenantId, 'payslip_id' => $payslipId, 'type' => $type, 'label' => $label, 'amount' => $amount, 'source_id' => $sourceId,
        ]);
    }
}
