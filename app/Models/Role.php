<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends TenantModel
{
    use HasFactory;

    protected $table = 'roles';

    protected $fillable = [
        'tenant_id',
        'slug',
        'name',
        'permissions',
    ];

    protected $casts = [
        'permissions' => 'array',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
