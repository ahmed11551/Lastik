<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KpiRule extends TenantModel
{
    protected $table = 'kpi_rules';

    protected $fillable = [
        'tenant_id',
        'applies_to',
        'target_type',
        'product_id',
        'role_id',
        'percent',
        'amount',
        'is_active',
        'valid_from',
        'valid_to',
    ];

    protected $casts = [
        'percent' => 'decimal:3',
        'amount' => 'decimal:2',
        'is_active' => 'boolean',
        'valid_from' => 'datetime',
        'valid_to' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
