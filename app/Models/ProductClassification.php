<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Models;

use Autometria\Models\Concerns\BelongsToTenant;
use Autometria\Models\ProductService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Persisted ABC/XYZ classification of a product within a tenant.
 *
 * @property int    $id
 * @property int    $tenant_id
 * @property int    $product_id
 * @property string $abc_class   A | B | C
 * @property string $xyz_class   X | Y | Z
 * @property float  $revenue_share       доля в выручке, %
 * @property float  $variation_coefficient  коэффициент вариации спроса, %
 * @property \Illuminate\Support\Carbon|null $calculated_at
 */
class ProductClassification extends Model
{
    use BelongsToTenant;

    protected $table = 'product_classifications';

    protected $fillable = [
        'tenant_id',
        'product_id',
        'abc_class',
        'xyz_class',
        'revenue_share',
        'variation_coefficient',
        'calculated_at',
    ];

    protected $casts = [
        'revenue_share' => 'float',
        'variation_coefficient' => 'float',
        'calculated_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(ProductService::class, 'product_id');
    }
}
