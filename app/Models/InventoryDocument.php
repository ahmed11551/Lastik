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

class InventoryDocument extends TenantModel
{
    protected $table = 'inventory_documents';

    protected $fillable = [
        'tenant_id',
        'warehouse_id',
        'target_warehouse_id',
        'type',
        'status',
        'number',
        'created_by',
        'posted_at',
    ];

    protected $casts = [
        'posted_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(InventoryDocumentItem::class, 'document_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function targetWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'target_warehouse_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function totalAmount(): float
    {
        return round((float) $this->items->sum(fn (InventoryDocumentItem $i) => (float) $i->quantity * (float) $i->cost_price), 2);
    }
}
