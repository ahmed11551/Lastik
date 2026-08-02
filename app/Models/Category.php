<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @package    Autometria\Core
 * @copyright  (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author     Себиев Ахмед Сулейманович (Chief Software Architect / Lead Developer)
 * @license    Proprietary & Confidential.
 */

declare(strict_types=1);

namespace Autometria\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends TenantModel
{
    protected $table = 'categories';

    protected $fillable = [
        'tenant_id',
        'name',
        'external_id',
        'parent_id',
    ];

    public function products(): HasMany
    {
        return $this->hasMany(ProductService::class, 'category_id');
    }
}
