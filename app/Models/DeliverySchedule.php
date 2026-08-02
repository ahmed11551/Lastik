<?php

/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        return $this->belongsTo(ProductService::class, 'product_id');
    }
}
