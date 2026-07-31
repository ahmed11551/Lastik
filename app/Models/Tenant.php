<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    use HasFactory;

    protected $table = 'tenants';

    protected $fillable = [
        'slug',
        'name',
        'timezone',
        'is_active',
        'support_access_enabled',
        'support_access_reason',
        'support_access_expiry',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'support_access_enabled' => 'boolean',
        'support_access_expiry' => 'datetime',
    ];

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
