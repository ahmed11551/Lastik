<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Models;

use Autometria\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

/**
 * Line item of a PurchaseOrderDraft.
 *
 * @property int    $id
 * @property int    $tenant_id
 * @property int    $purchase_order_draft_id
 * @property int    $product_id
 * @property float  $suggested_qty
 * @property float  $approved_qty
 * @property float  $unit_cost
 * @property float  $subtotal
 */
class PurchaseOrderDraftItem extends Model
{
    use BelongsToTenant;

    protected $table = 'purchase_order_draft_items';

    protected $fillable = [
        'tenant_id',
        'purchase_order_draft_id',
        'product_id',
        'suggested_qty',
        'approved_qty',
        'unit_cost',
        'subtotal',
    ];

    protected $casts = [
        'suggested_qty' => 'float',
        'approved_qty' => 'float',
        'unit_cost' => 'float',
        'subtotal' => 'float',
    ];

    public function draft(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderDraft::class, 'purchase_order_draft_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(ProductService::class, 'product_id');
    }
}
