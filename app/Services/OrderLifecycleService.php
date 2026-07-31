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

namespace Autometria\Services;

use Autometria\Models\Order;
use Autometria\Models\OrderItem;
use Autometria\Models\Reservation;
use Autometria\Support\AuditLog;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final class OrderLifecycleService
{
    public function __construct(
        private readonly IssuanceService $issuances,
        private readonly StockReservationService $reservations,
    ) {}

    public function cancel(int $tenantId, int $orderId, int $userId, string $reason): Order
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Cancel reason is required');
        }

        return DB::transaction(function () use ($tenantId, $orderId, $userId, $reason): Order {
            set_current_tenant_id($tenantId);

            $order = Order::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereKey($orderId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($order->status === Order::STATUS_CANCELLED) {
                return $order;
            }

            if ($order->status === Order::STATUS_CLOSED) {
                throw new RuntimeException('Closed order cannot be cancelled without correction');
            }

            $items = OrderItem::query()->withoutGlobalScopes()
                ->where('order_id', $order->id)
                ->get();

            foreach ($items as $item) {
                $active = Reservation::query()->withoutGlobalScopes()
                    ->where('order_item_id', $item->id)
                    ->where('status', Reservation::STATUS_ACTIVE)
                    ->get();

                foreach ($active as $reservation) {
                    $this->reservations->release(
                        (int) $reservation->stock_id,
                        $tenantId,
                        (float) $reservation->qty,
                        (int) $item->id,
                    );
                }
            }

            $old = ['status' => $order->status];
            $order->update(['status' => Order::STATUS_CANCELLED]);

            AuditLog::write(
                $tenantId,
                $userId,
                'order.cancelled',
                Order::class,
                (int) $order->id,
                $old,
                ['status' => Order::STATUS_CANCELLED],
                ['location_id' => $order->location_id],
                $reason,
            );

            return $order->fresh();
        });
    }

    public function removeItem(int $tenantId, int $orderItemId, int $userId, string $reason): void
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Delete reason is required');
        }

        DB::transaction(function () use ($tenantId, $orderItemId, $userId, $reason): void {
            $item = OrderItem::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereKey($orderItemId)
                ->lockForUpdate()
                ->firstOrFail();

            $this->issuances->assertItemDeletable($item);

            $active = Reservation::query()->withoutGlobalScopes()
                ->where('order_item_id', $item->id)
                ->where('status', Reservation::STATUS_ACTIVE)
                ->get();

            foreach ($active as $reservation) {
                $this->reservations->release(
                    (int) $reservation->stock_id,
                    $tenantId,
                    (float) $reservation->qty,
                    (int) $item->id,
                );
            }

            AuditLog::write(
                $tenantId,
                $userId,
                'order_item.deleted',
                OrderItem::class,
                (int) $item->id,
                $item->only(['product_id', 'qty', 'price', 'snapshot']),
                [],
                ['order_id' => $item->order_id],
                $reason,
            );

            $item->delete();
        });
    }
}
