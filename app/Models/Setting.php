<?php

declare(strict_types=1);

namespace App\Models;

class Setting extends TenantModel
{
    protected $table = 'settings';

    protected $fillable = [
        'tenant_id',
        'group',
        'key',
        'value',
        'scope',
        'ref_id',
    ];

    protected $casts = [
        'value' => 'array',
        'ref_id' => 'integer',
    ];
}
