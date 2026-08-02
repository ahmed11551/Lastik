<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class Modifier extends TenantModel
{
    protected $table = 'modifiers';

    protected $fillable = [
        'tenant_id',
        'name',
        'code',
        'is_required',
        'min_select',
        'max_select',
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'min_select' => 'integer',
        'max_select' => 'integer',
    ];

    public function options(): HasMany
    {
        return $this->hasMany(ModifierOption::class);
    }

    public function productLinks(): HasMany
    {
        return $this->hasMany(ProductModifier::class);
    }
}
