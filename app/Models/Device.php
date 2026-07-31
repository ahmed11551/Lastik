<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Device extends TenantModel
{
    protected $table = 'devices';

    protected $fillable = [
        'user_id',
        'device_name',
        'device_type',
        'fingerprint',
        'ip_address',
        'user_agent',
        'is_active',
        'last_active_at',
        'is_current',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_current' => 'boolean',
        'last_active_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
