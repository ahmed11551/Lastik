<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @package    Autometria\Core
 * @copyright  (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author     Себиев Ахмед Сулейманович (Chief Software Architect / Lead Developer)
 * @license    Proprietary & Confidential.
 */

declare(strict_types=1);

namespace Autometria\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockBatch extends TenantModel
{
    protected $table = 'stock_batches';

    protected $fillable = [
        'warehouse_id',
        'product_id',
        'supplier_order_id',
        'batch_number',
        'qty',
        'remaining_qty',
        'cost_price',
        'received_at',
        'is_overdraft',
    ];

    protected $casts = [
        'qty' => 'decimal:3',
        'remaining_qty' => 'decimal:3',
        'cost_price' => 'decimal:2',
        'received_at' => 'datetime',
        'is_overdraft' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(ProductService::class, 'product_id');
    }
}
