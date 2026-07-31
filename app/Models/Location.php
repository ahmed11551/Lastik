<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Location extends TenantModel
{
    use HasFactory;

    protected $table = 'locations';

    protected $fillable = [
        'tenant_id',
        'name',
        'address',
        'timezone',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function warehouses(): HasMany
    {
        return $this->hasMany(Warehouse::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function cashShifts(): HasMany
    {
        return $this->hasMany(CashShift::class);
    }
}
