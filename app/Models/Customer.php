<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends TenantModel
{
    public const TYPE_INDIVIDUAL = 'individual';

    public const TYPE_LEGAL = 'legal';

    protected $table = 'customers';

    protected $fillable = [
        'type',
        'name',
        'phone',
        'email',
        'inn',
        'kpp',
        'legal_name',
    ];

    protected $casts = [
        'type' => 'string',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
