<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Recipe extends TenantModel
{
    protected $table = 'recipes';

    protected $fillable = [
        'tenant_id',
        'product_id',
        'yield_quantity',
        'instructions',
    ];

    protected $casts = [
        'yield_quantity' => 'decimal:3',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(ProductService::class, 'product_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(RecipeItem::class);
    }
}
