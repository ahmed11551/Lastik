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
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

/**
 * Auto-generated procurement draft (1-click from demand forecast).
 *
 * @property int    $id
 * @property int    $tenant_id
 * @property int    $supplier_id
 * @property string $status   draft | approved | sent | cancelled
 * @property float  $total_amount
 * @property string $currency
 * @property string|null $notes
 */
class PurchaseOrderDraft extends Model
{
    use BelongsToTenant;

    protected $table = 'purchase_order_drafts';

    protected $fillable = [
        'tenant_id',
        'supplier_id',
        'status',
        'total_amount',
        'currency',
        'notes',
    ];

    protected $casts = [
        'total_amount' => 'float',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderDraftItem::class);
    }
}
