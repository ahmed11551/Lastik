<?php

declare(strict_types=1);

namespace Autometria\Models;

class AccrualRule extends TenantModel
{
    public const TYPE_KPI_PERCENT = 'KPI_PERCENT';
    public const TYPE_FIXED = 'FIXED';
    public const TYPE_BONUS = 'BONUS';

    protected $table = 'accrual_rules';
    protected $fillable = ['tenant_id', 'name', 'type', 'value', 'is_active'];
    protected $casts = ['value' => 'decimal:2', 'is_active' => 'boolean'];
}
