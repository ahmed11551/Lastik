<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Events;

use Autometria\Models\Order;

/**
 * Fired on Order Eloquent `updated` (via $dispatchesEvents).
 * Payload fields are always set; listeners must respect {@see $statusChanged}.
 */
final class OrderStatusChanged
{
    public readonly int $tenantId;

    public readonly ?int $locationId;

    public readonly int $orderId;

    public readonly string $oldStatus;

    public readonly string $newStatus;

    /** True only when `status` was among dirty attributes of this save. */
    public readonly bool $statusChanged;

    /**
     * Eloquent $dispatchesEvents passes the model; we project the domain payload.
     *
     * Equivalent domain shape: (tenantId, locationId, orderId, oldStatus, newStatus).
     */
    public function __construct(Order $order)
    {
        $this->statusChanged = $order->wasChanged('status');
        $this->tenantId = (int) $order->tenant_id;
        $this->locationId = $order->location_id !== null ? (int) $order->location_id : null;
        $this->orderId = (int) $order->id;
        $this->newStatus = (string) $order->status;
        $this->oldStatus = $this->statusChanged
            ? (string) ($order->statusBeforeLastSave ?? '')
            : (string) $order->status;
    }
}
