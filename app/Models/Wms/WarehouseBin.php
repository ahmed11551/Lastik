<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Models\Wms;

use Autometria\Models\Tenant;
use Autometria\Models\TenantModel;
use Autometria\Models\Warehouse;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WarehouseBin extends TenantModel
{
    protected $table = 'warehouse_bins';

    public const ZONE_RECEIVING = 'RECEIVING';

    public const ZONE_STORAGE = 'STORAGE';

    public const ZONE_PICKING = 'PICKING';

    public const ZONE_SHIPPING = 'SHIPPING';

    protected $fillable = [
        'tenant_id',
        'warehouse_id',
        'code',
        'zone',
        'max_weight_kg',
        'max_volume_m3',
        'is_active',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'warehouse_id' => 'integer',
        'is_active' => 'boolean',
        'max_weight_kg' => 'string',
        'max_volume_m3' => 'string',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function batches(): HasMany
    {
        return $this->hasMany(StockBatch::class, 'warehouse_bin_id');
    }
}
