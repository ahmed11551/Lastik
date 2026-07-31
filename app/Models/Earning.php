<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Earning extends TenantModel
{
    protected $table = 'earnings';

    protected $fillable = [
        'tenant_id',
        'order_id',
        'user_id',
        'order_item_id',
        'amount',
        'percent',
        'rule_snapshot',
        'source',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'percent' => 'decimal:3',
        'rule_snapshot' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
