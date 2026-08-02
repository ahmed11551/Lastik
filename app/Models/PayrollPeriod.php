<?php

/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author Себиев Ахмед Сулейманович
 * @license Proprietary & Confidential.
 */

namespace Autometria\Models;

use Autometria\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollPeriod extends TenantModel
{
    protected $table = 'payroll_periods';

    protected $fillable = [
        'tenant_id',
        'name',
        'period_from',
        'period_to',
        'status',
        'total_gross',
        'total_deductions',
        'total_net',
        'paid_at',
    ];

    protected $casts = [
        'total_gross' => 'decimal:2',
        'total_deductions' => 'decimal:2',
        'total_net' => 'decimal:2',
        'period_from' => 'date',
        'period_to' => 'date',
        'paid_at' => 'datetime',
    ];

    public const STATUS_DRAFT = 'DRAFT';
    public const STATUS_CALCULATED = 'CALCULATED';
    public const STATUS_APPROVED = 'APPROVED';
    public const STATUS_PAID = 'PAID';

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }
}

class Payslip extends TenantModel
{
    protected $table = 'payslips';

    protected $fillable = [
        'tenant_id',
        'payroll_period_id',
        'user_id',
        'gross',
        'deductions_total',
        'net',
        'status',
    ];

    protected $casts = [
        'gross' => 'decimal:2',
        'deductions_total' => 'decimal:2',
        'net' => 'decimal:2',
    ];

    public function payrollPeriod(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PayslipItem::class);
    }
}

class PayslipItem extends TenantModel
{
    protected $table = 'payslip_items';

    protected $fillable = [
        'tenant_id',
        'payslip_id',
        'type',
        'label',
        'amount',
        'source_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public const TYPE_EARNING = 'EARNING';
    public const TYPE_DEDUCTION = 'DEDUCTION';

    public function payslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class);
    }
}

class Deduction extends TenantModel
{
    protected $table = 'deductions';

    protected $fillable = [
        'tenant_id',
        'name',
        'type',
        'value',
        'is_active',
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public const TYPE_FIXED = 'FIXED';
    public const TYPE_PERCENT = 'PERCENT';
}

class AccrualRule extends TenantModel
{
    protected $table = 'accrual_rules';

    protected $fillable = [
        'tenant_id',
        'name',
        'type',
        'value',
        'is_active',
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public const TYPE_KPI_PERCENT = 'KPI_PERCENT';
    public const TYPE_FIXED = 'FIXED';
    public const TYPE_BONUS = 'BONUS';
}
