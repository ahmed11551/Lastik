<?php

/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author Себиев Ахмед Сулейманович
 * @license Proprietary & Confidential.
 */

namespace Autometria\Models;

use Autometria\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierOrderItem extends TenantModel
{
    protected $table = 'supplier_order_items';

    protected $fillable = [
        'tenant_id',
        'supplier_order_id',
        'product_id',
        'qty',
        'received_qty',
        'unit_price',
        'planned_delivery',
    ];

    protected $casts = [
        'qty' => 'decimal:3',
        'received_qty' => 'decimal:3',
        'unit_price' => 'decimal:2',
        'planned_delivery' => 'date',
    ];

    public function supplierOrder(): BelongsTo
    {
        return $this->belongsTo(SupplierOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(ProductService::class);
    }
}
