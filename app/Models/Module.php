<?php

declare(strict_types=1);

namespace App\Models;

class Module extends TenantModel
{
    public const STATUS_AVAILABLE = 'available';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISABLED = 'disabled';

    public const STATUS_BLOCKED = 'blocked';

    protected $table = 'modules';

    protected $fillable = [
        'tenant_id',
        'slug',
        'status',
        'enabled_at',
        'disabled_at',
        'settings',
    ];

    protected $casts = [
        'settings' => 'array',
        'enabled_at' => 'datetime',
        'disabled_at' => 'datetime',
    ];
}
