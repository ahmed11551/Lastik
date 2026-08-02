<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Refund extends TenantModel
{
    protected $table = 'refunds';

    protected $fillable = [
        'tenant_id',
        'order_id',
        'payment_id',
        'fiscal_receipt_id',
        'cash_shift_id',
        'status',
        'reason',
        'total_amount',
        'created_by',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(RefundItem::class);
    }

    public function fiscalReceipt(): BelongsTo
    {
        return $this->belongsTo(FiscalReceipt::class, 'fiscal_receipt_id');
    }
}
