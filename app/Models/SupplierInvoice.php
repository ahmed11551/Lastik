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

class SupplierInvoice extends TenantModel
{
    protected $table = 'supplier_invoices';

    protected $fillable = [
        'tenant_id',
        'supplier_order_id',
        'number',
        'invoice_date',
        'amount',
        'file_path',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'invoice_date' => 'date',
    ];

    public function supplierOrder(): BelongsTo
    {
        return $this->belongsTo(SupplierOrder::class);
    }
}

class DeliverySchedule extends TenantModel
{
    protected $table = 'delivery_schedules';

    protected $fillable = [
        'tenant_id',
        'supplier_order_id',
        'product_id',
        'planned_date',
        'qty',
    ];

    protected $casts = [
        'qty' => 'decimal:3',
        'planned_date' => 'date',
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
