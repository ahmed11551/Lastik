<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductService extends TenantModel
{
    public const TYPE_PRODUCT = 'product';

    public const TYPE_SERVICE = 'service';

    protected $table = 'products_services';

    protected $fillable = [
        'tenant_id',
        'type',
        'article',
        'external_id',
        'name',
        'brand',
        'unit',
        'category',
        'base_price',
        'radius_modifier',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'base_price' => 'decimal:2',
        'radius_modifier' => 'array',
    ];

    public function stocks(): HasMany
    {
        return $this->hasMany(Stock::class, 'product_id');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(Price::class, 'product_id');
    }
}
