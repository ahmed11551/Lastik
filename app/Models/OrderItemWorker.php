<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItemWorker extends TenantModel
{
    protected $table = 'order_item_workers';

    protected $fillable = [
        'tenant_id',
        'order_item_id',
        'worker_id',
        'commission_rate',
        'earned_amount',
    ];

    protected $casts = [
        'commission_rate' => 'decimal:2',
        'earned_amount' => 'decimal:2',
    ];

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'worker_id');
    }
}
