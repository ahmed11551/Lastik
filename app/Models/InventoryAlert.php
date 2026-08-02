<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryAlert extends TenantModel
{
    public const TYPE_OVERDRAFT = 'OVERDRAFT';

    protected $table = 'inventory_alerts';

    protected $fillable = [
        'tenant_id',
        'product_id',
        'warehouse_id',
        'type',
        'message',
        'resolved_at',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(ProductService::class, 'product_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
