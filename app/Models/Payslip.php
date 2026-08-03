<?php

declare(strict_types=1);

namespace Autometria\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payslip extends TenantModel
{
    protected $table = 'payslips';

    protected $fillable = ['tenant_id', 'payroll_period_id', 'user_id', 'gross', 'deductions_total', 'net', 'status'];

    protected $casts = ['gross' => 'decimal:2', 'deductions_total' => 'decimal:2', 'net' => 'decimal:2'];

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
