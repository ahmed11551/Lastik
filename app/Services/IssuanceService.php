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

use Autometria\Exceptions\Domain\InsufficientStockException;
use Autometria\Models\Issuance;
use Autometria\Models\Order;
use Autometria\Models\OrderItem;
use Autometria\Models\Reservation;
use Autometria\Support\AuditLog;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final class IssuanceService
{
    public function __construct(
        private readonly OrderFulfillmentService $fulfillment,
    ) {}

    public function issue(
        int $tenantId,
        int $orderId,
        int $orderItemId,
        float $qty,
        int $issuedBy,
        string $basis = Issuance::BASIS_TO_CUSTOMER,
        ?string $note = null,
    ): Issuance {
        if ($qty <= 0) {
            throw new InvalidArgumentException('Issuance qty must be positive');
        }

        return DB::transaction(function () use ($tenantId, $orderId, $orderItemId, $qty, $issuedBy, $basis, $note): Issuance {
            set_current_tenant_id($tenantId);

            $order = Order::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereKey($orderId)
                ->lockForUpdate()
                ->firstOrFail();

            $item = OrderItem::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('order_id', $order->id)
                ->whereKey($orderItemId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($item->type !== 'product') {
                throw new InvalidArgumentException('Only product items can be issued');
            }

            $alreadyIssued = (float) Issuance::query()->withoutGlobalScopes()
                ->where('order_item_id', $item->id)
                ->sum('qty');

            if ($alreadyIssued + $qty > (float) $item->qty + 0.0001) {
                throw new InvalidArgumentException('Cannot issue more than ordered qty');
            }

            $reservation = Reservation::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('order_item_id', $item->id)
                ->where('status', Reservation::STATUS_ACTIVE)
                ->lockForUpdate()
                ->first();

            if ($reservation === null) {
                throw new RuntimeException('Active reservation required before issuance');
            }

            if ((float) $reservation->qty + 0.0001 < $qty) {
                throw new InsufficientStockException('Reservation qty less than issuance qty');
            }

            $stock = $this->fulfillment->deductIssuedQty(
                (int) $reservation->stock_id,
                $tenantId,
                $qty,
            );

            if ((float) $reservation->qty <= $qty + 0.0001) {
                $reservation->update(['status' => Reservation::STATUS_USED]);
            } else {
                $reservation->update(['qty' => (float) $reservation->qty - $qty]);
            }

            $issuance = Issuance::query()->withoutGlobalScopes()->forceCreate([
                'tenant_id' => $tenantId,
                'order_id' => $order->id,
                'order_item_id' => $item->id,
                'warehouse_id' => $stock->warehouse_id,
                'qty' => $qty,
                'type' => 'product',
                'basis' => $basis,
                'note' => $note,
                'issued_by' => $issuedBy,
                'issued_at' => now(),
            ]);

            $snapshot = $item->snapshot ?? [];
            $snapshot['item_status'] = 'issued';
            $snapshot['issued_qty'] = $alreadyIssued + $qty;
            $item->update(['snapshot' => $snapshot]);

            if ($order->status === Order::STATUS_CREATED) {
                $order->update(['status' => Order::STATUS_IN_PROGRESS]);
            }

            AuditLog::write(
                $tenantId,
                $issuedBy,
                'issuance.created',
                Issuance::class,
                (int) $issuance->id,
                [],
                [
                    'order_id' => $order->id,
                    'order_item_id' => $item->id,
                    'qty' => $qty,
                    'warehouse_id' => $stock->warehouse_id,
                    'basis' => $basis,
                ],
                ['location_id' => $order->location_id],
                $note,
            );

            return $issuance;
        });
    }

    public function assertItemDeletable(OrderItem $item): void
    {
        $issued = Issuance::query()->withoutGlobalScopes()
            ->where('order_item_id', $item->id)
            ->exists();

        if ($issued) {
            throw new RuntimeException('Issued item cannot be deleted directly; use correction or return');
        }
    }
}
