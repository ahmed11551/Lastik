<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecipeItem extends TenantModel
{
    protected $table = 'recipe_items';

    protected $fillable = [
        'tenant_id',
        'recipe_id',
        'ingredient_id',
        'quantity',
        'waste_percentage',
        'net_quantity',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'waste_percentage' => 'decimal:3',
        'net_quantity' => 'decimal:3',
    ];

    protected static function booted(): void
    {
        static::saving(function (RecipeItem $item): void {
            $gross = (float) $item->quantity;
            $waste = max(0.0, min(99.999, (float) ($item->waste_percentage ?? 0)));
            $item->net_quantity = round($gross * (1 - $waste / 100), 3);
        });
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(ProductService::class, 'ingredient_id');
    }

    /**
     * Gross qty to write off for one yield portion (брутто already includes waste).
     */
    public function grossPerYield(): float
    {
        return round((float) $this->quantity, 3);
    }
}
