<?php

declare(strict_types=1);

namespace App\Models;

class ModuleRegistry extends TenantModel
{
    protected $fillable = [
        'slug',
        'status',
        'enabled_at',
        'disabled_at',
        'settings',
    ];

    protected $casts = [
        'enabled_at' => 'datetime',
        'disabled_at' => 'datetime',
        'settings' => 'array',
    ];
}
