<?php

/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author Себиев Ахмед Сулейманович
 * @license Proprietary & Confidential.
 *
 * v1.1.0 / Вектор 4.B — WMS Light: складские ячейки (зона/стеллаж/полка/бин).
 */

namespace Autometria\Models;

use Autometria\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StorageCell extends TenantModel
{
    protected $table = 'storage_cells';

    protected $fillable = [
        'tenant_id',
        'warehouse_id',
        'code',
        'zone',
        'rack',
        'shelf',
        'bin',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function batchPlacements(): HasMany
    {
        return $this->hasMany(StockBatchCell::class);
    }
}
