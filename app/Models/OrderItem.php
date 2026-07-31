<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderItem extends TenantModel
{
    protected $table = 'order_items';

    protected $fillable = [
        'tenant_id',
        'order_id',
        'type',
        'product_id',
        'snapshot',
        'qty',
        'price',
        'discount',
        'kpi_percent',
        'kpi_amount',
    ];

    protected $casts = [
        'snapshot' => 'array',
        'qty' => 'decimal:3',
        'price' => 'decimal:2',
        'discount' => 'decimal:2',
        'kpi_percent' => 'decimal:3',
        'kpi_amount' => 'decimal:2',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(ProductService::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }
}
