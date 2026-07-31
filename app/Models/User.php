<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use HasFactory;

    protected $table = 'users';

    protected $fillable = [
        'tenant_id',
        'location_id',
        'role_id',
        'name',
        'email',
        'phone',
        'email_verified_at',
        'password_hash',
        'two_factor_secret',
        'devices_limit',
        'last_login_at',
        'is_active',
    ];

    protected $hidden = [
        'password_hash',
        'two_factor_secret',
    ];

    protected $casts = [
        'devices_limit' => 'integer',
        'is_active' => 'boolean',
        'email_verified_at' => 'datetime',
        'last_login_at' => 'datetime',
    ];

    public function getAuthPassword(): string
    {
        return (string) $this->password_hash;
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }
}
