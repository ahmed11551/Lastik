<?php

/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author Себиев Ахмед Сулейманович
 * @license Proprietary & Confidential.
 *
 * v1.1.0 / Вектор 4.B — WMS Light: размещение партии в ячейке.
 */

namespace Autometria\Models;

use Autometria\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockBatchCell extends TenantModel
{
    protected $table = 'stock_batch_cells';

    protected $fillable = [
        'tenant_id',
        'stock_batch_id',
        'storage_cell_id',
        'quantity',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
    ];

    public function stockBatch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class);
    }

    public function cell(): BelongsTo
    {
        return $this->belongsTo(StorageCell::class, 'storage_cell_id');
    }
}
