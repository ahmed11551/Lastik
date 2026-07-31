<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Price extends TenantModel
{
    protected $table = 'prices';

    protected $fillable = [
        'product_id',
        'tenant_id',
        'type',
        'price',
        'cost_price',
        'amount',
        'valid_from',
        'valid_to',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'cost_price' => 'decimal:2',
        'amount' => 'decimal:2',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(ProductService::class);
    }
}
