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
