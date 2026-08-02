<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ModifierOption extends TenantModel
{
    protected $table = 'modifier_options';

    protected $fillable = [
        'tenant_id',
        'modifier_id',
        'name',
        'price',
        'ingredient_id',
        'ingredient_qty',
        'is_active',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'ingredient_qty' => 'decimal:3',
        'is_active' => 'boolean',
    ];

    public function modifier(): BelongsTo
    {
        return $this->belongsTo(Modifier::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(ProductService::class, 'ingredient_id');
    }
}
