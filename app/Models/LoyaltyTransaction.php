<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoyaltyTransaction extends TenantModel
{
    protected $table = 'loyalty_transactions';

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'receipt_id',
        'order_id',
        'type',
        'amount',
        'balance_after',
        'meta',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'balance_after' => 'decimal:2',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
