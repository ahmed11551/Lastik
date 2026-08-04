<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @package    Autometria\Core
 * @copyright  (c) 2026 Себиев Ахмед Сулейманович (Sebiev Akhmed Suleymanovich). All Rights Reserved.
 * @author     Себиев Ахмед Сулейманович (Chief Software Architect / Lead Developer)
 * @license    Proprietary & Confidential. Unauthorized copying, distribution,
 *             modification, or reverse engineering of this file, via any medium,
 *             is strictly prohibited.
 *
 * NOTICE: All information contained herein is, and remains the property of
 * Себиев Ахмед Сулейманович. The intellectual and technical concepts contained
 * herein are proprietary and protected by trade secret and copyright law.
 */
/**
 * LASTIK B2B SaaS Engine Core
 *
 * @copyright  (c) 2026 Себиев Ахмед Сулейманович (Sebiev Akhmed Suleymanovich). All Rights Reserved.
 * @author     Себиев Ахмед Сулейманович (Chief Software Architect / Lead Developer)
 * @license    Proprietary & Confidential. Unauthorized copying, distribution,
 *             modification, or reverse engineering of this file, via any medium,
 *             is strictly prohibited.
 *
 * NOTICE: All information contained herein is, and remains the property of
 * Себиев Ахмед Сулейманович. The intellectual and technical concepts contained
 * herein are proprietary and protected by trade secret and copyright law.
 */
/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author Себиев Ахмед Сулейманович
 * @license Proprietary & Confidential.
 */

declare(strict_types=1);

namespace Autometria\Models;

use Autometria\Enums\OrderStatusEnum;
use Autometria\Enums\PaymentStatusEnum;
use Autometria\Events\OrderStatusChanged;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends TenantModel
{
    public const STATUS_CREATED = OrderStatusEnum::CREATED->value;

    public const STATUS_IN_PROGRESS = OrderStatusEnum::IN_PROGRESS->value;

    public const STATUS_READY = OrderStatusEnum::READY->value;

    public const STATUS_ISSUED = OrderStatusEnum::ISSUED->value;

    public const STATUS_CLOSED = OrderStatusEnum::CLOSED->value;

    public const STATUS_CANCELLED = OrderStatusEnum::CANCELLED->value;

    protected $table = 'orders';

    /**
     * @var array<string, class-string<object>>
     */
    protected $dispatchesEvents = [
        'updated' => OrderStatusChanged::class,
    ];

    /**
     * Transient: previous status captured in updating when status is dirty.
     * Not a DB column — used by {@see OrderStatusChanged} after syncOriginal().
     */
    public ?string $statusBeforeLastSave = null;

    protected $fillable = [
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

    protected static function booted(): void
    {
        static::updating(function (Order $order): void {
            if ($order->isDirty('status')) {
                $order->statusBeforeLastSave = (string) ($order->getOriginal('status') ?? '');
            }
        });
    }

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

    /**
     * Apply UI list filter token from query param `status`.
     */
    public function scopeWithListStatusFilter(Builder $query, string $filter): Builder
    {
        if (OrderStatusEnum::isPaidListFilter($filter)) {
            return $query->where('payment_status', PaymentStatusEnum::PAID->value);
        }

        $values = OrderStatusEnum::valuesForListFilter($filter);
        if ($values !== null) {
            return $query->whereIn('status', $values);
        }

        $status = OrderStatusEnum::tryFrom($filter);

        return $status !== null
            ? $query->where('status', $status->value)
            : $query;
    }

    /**
     * Apply payment filter from query param `payment`.
     */
    public function scopeWithPaymentFilter(Builder $query, string $filter): Builder
    {
        $status = PaymentStatusEnum::fromApiFilter($filter);

        return $status !== null
            ? $query->where('payment_status', $status->value)
            : $query;
    }
}
