<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class Post extends TenantModel
{
    protected $table = 'posts';

    protected $fillable = [
        'tenant_id',
        'name',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }
}
