<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Permission extends TenantModel
{
    protected $table = 'permissions';

    protected $fillable = [
        'slug',
        'section',
        'action',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
