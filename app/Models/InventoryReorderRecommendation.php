<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryReorderRecommendation extends TenantModel
{
    protected $table = 'inventory_reorder_recommendations';

    protected $fillable = [
        'tenant_id',
        'warehouse_id',
        'product_id',
        'd_avg',
        'safety_stock',
        'rop',
        'on_hand',
        'suggested_qty',
        'is_dead_stock',
        'severity',
        'lead_time_days',
        'lookback_days',
        'calculated_at',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'warehouse_id' => 'integer',
        'product_id' => 'integer',
        'd_avg' => 'string',
        'safety_stock' => 'string',
        'rop' => 'string',
        'on_hand' => 'string',
        'suggested_qty' => 'string',
        'is_dead_stock' => 'boolean',
        'lead_time_days' => 'integer',
        'lookback_days' => 'integer',
        'calculated_at' => 'datetime',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(ProductService::class, 'product_id');
    }
}
