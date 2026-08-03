<?php

declare(strict_types=1);

namespace Autometria\Models;

class Deduction extends TenantModel
{
    public const TYPE_FIXED = 'FIXED';
    public const TYPE_PERCENT = 'PERCENT';

    protected $table = 'deductions';
    protected $fillable = ['tenant_id', 'name', 'type', 'value', 'is_active'];
    protected $casts = ['value' => 'decimal:2', 'is_active' => 'boolean'];
}
