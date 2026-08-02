<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RefundItem extends TenantModel
{
    protected $table = 'refund_items';

    protected $fillable = [
        'tenant_id',
        'refund_id',
        'order_item_id',
        'product_id',
        'qty',
        'amount',
        'marking_code',
    ];

    protected $casts = [
        'qty' => 'decimal:3',
        'amount' => 'decimal:2',
    ];

    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}
