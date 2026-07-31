<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Issuance extends TenantModel
{
    public const BASIS_TO_CUSTOMER = 'to_customer';

    public const BASIS_TO_WORK = 'to_work';

    protected $table = 'issuances';

    protected $fillable = [
        'order_id',
        'order_item_id',
        'warehouse_id',
        'qty',
        'type',
        'note',
        'basis',
        'issued_by',
        'issued_at',
    ];

    protected $casts = [
        'qty' => 'decimal:3',
        'issued_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }
}
