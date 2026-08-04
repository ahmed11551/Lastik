<?php

/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author Себиев Ахмед Сулейманович
 * @license Proprietary & Confidential.
 *
 * v1.1.0 / Вектор 4.B — WMS Light: серийные номера деталей/товаров.
 */

namespace Autometria\Models;

use Autometria\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SerialNumber extends TenantModel
{
    protected $table = 'serial_numbers';

    protected $fillable = [
        'tenant_id',
        'product_id',
        'stock_batch_id',
        'warehouse_id',
        'serial',
        'status',
    ];

    public const STATUS_IN_STOCK = 'IN_STOCK';
    public const STATUS_SOLD = 'SOLD';
    public const STATUS_WRITTEN_OFF = 'WRITTEN_OFF';

    public function product(): BelongsTo
    {
        return $this->belongsTo(ProductService::class);
    }

    public function stockBatch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
