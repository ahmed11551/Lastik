<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Models\Wms;

use Autometria\Models\ProductService;
use Autometria\Models\Tenant;
use Autometria\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * WMS 2.0 stock batch projection (same physical table as legacy FIFO StockBatch).
 * Quantity / expiration_date are BCMath string / date fields for bin FEFO.
 */
class StockBatch extends TenantModel
{
    protected $table = 'stock_batches';

    protected $fillable = [
        'tenant_id',
        'product_id',
        'warehouse_bin_id',
        'batch_number',
        'serial_number',
        'quantity',
        'expiration_date',
        'received_at',
        // Legacy FIFO columns kept writable for coexistence with StockBatchService.
        'warehouse_id',
        'qty',
        'remaining_qty',
        'cost_price',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'product_id' => 'integer',
        'warehouse_bin_id' => 'integer',
        'quantity' => 'string',
        'expiration_date' => 'date',
        'received_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(ProductService::class, 'product_id');
    }

    public function bin(): BelongsTo
    {
        return $this->belongsTo(WarehouseBin::class, 'warehouse_bin_id');
    }
}
