<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Vehicle extends TenantModel
{
    protected $table = 'vehicles';

    protected $fillable = [
        'customer_id',
        'plate',
        'vin',
        'brand',
        'model',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
