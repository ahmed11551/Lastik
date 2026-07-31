<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends TenantModel
{
    public const STATUS_CREATED = 'created';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_READY = 'ready';

    public const STATUS_ISSUED = 'issued';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'orders';

    protected $fillable = [
        'tenant_id',
        'location_id',
        'customer_id',
        'vehicle_id',
        'scenario',
        'number',
        'status',
        'payment_status',
        'shift_id',
        'assigned_seller_id',
        'master_id',
        'total',
        'created_by',
        'locked_at',
    ];

    protected $casts = [
        'total' => 'decimal:2',
        'locked_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
