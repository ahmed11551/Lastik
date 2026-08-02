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

class StockLotDeduction extends TenantModel
{
    protected $table = 'stock_lot_deductions';

    protected $fillable = [
        'tenant_id',
        'order_id',
        'order_item_id',
        'stock_batch_id',
        'warehouse_id',
        'product_id',
        'quantity',
        'unit_cost',
        'total_cost',
        'deducted_at',
        'refunded_qty',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'refunded_qty' => 'decimal:3',
        'unit_cost' => 'decimal:2',
        'total_cost' => 'decimal:2',
        'deducted_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function stockBatch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(ProductService::class, 'product_id');
    }
}
