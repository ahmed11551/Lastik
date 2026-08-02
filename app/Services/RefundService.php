<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Services;

use Autometria\Enums\FiscalReceiptStatus;
use Autometria\Enums\OrderStatusEnum;
use Autometria\Enums\RefundStatusEnum;
use Autometria\Exceptions\Domain\RefundException;
use Autometria\Models\CashShift;
use Autometria\Models\Order;
use Autometria\Models\OrderItem;
use Autometria\Models\Payment;
use Autometria\Models\Refund;
use Autometria\Models\RefundItem;
use Autometria\Services\Fiscal\FiscalReceiptService;
use Autometria\Services\Marking\EgaisAndMarkingService;
use Illuminate\Support\Facades\DB;

/**
 * Блок 3.4: возврат товара — reverse FIFO + раскрепление марок + чек возврата прихода.
 */
final class RefundService
{
    public function __construct(
        private readonly StockBatchService $batches,
        private readonly FiscalReceiptService $fiscal,
        private readonly EgaisAndMarkingService $marking,
    ) {}

    /**
     * @param  list<array{order_item_id: int, qty: float}>  $items
     */
    public function refundOrder(
        int $tenantId,
        int $orderId,
        array $items,
        int $createdBy,
        ?string $reason = null,
        ?int $cashShiftId = null,
    ): Refund {
        if ($items === []) {
            throw new RefundException('Список позиций возврата пуст', 'REFUND_ITEMS_REQUIRED');
        }

        return DB::transaction(function () use ($tenantId, $orderId, $items, $createdBy, $reason, $cashShiftId): Refund {
            $order = Order::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereKey($orderId)
                ->lockForUpdate()
                ->firstOrFail();

            if (in_array((string) $order->status, [
                OrderStatusEnum::CANCELLED->value,
                OrderStatusEnum::REFUNDED->value,
            ], true)) {
                throw new RefundException('Заказ недоступен для возврата', 'REFUND_ORDER_LOCKED');
            }

            $payment = Payment::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('order_id', $orderId)
                ->where('status', 'paid')
                ->latest('id')
                ->first();

            $shiftId = $cashShiftId;
            if ($shiftId === null) {
                $shiftId = CashShift::query()->withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->whereNull('closed_at')
                    ->latest('id')
                    ->value('id');
            }

            $refund = Refund::query()->withoutGlobalScopes()->forceCreate([
                'tenant_id' => $tenantId,
                'order_id' => $orderId,
                'payment_id' => $payment?->id,
                'cash_shift_id' => $shiftId,
                'status' => RefundStatusEnum::PENDING->value,
                'reason' => $reason,
                'total_amount' => 0,
                'created_by' => $createdBy,
            ]);

            $total = 0.0;
            $fiscalItems = [];

            foreach ($items as $row) {
                $orderItemId = (int) $row['order_item_id'];
                $qty = round((float) $row['qty'], 3);
                if ($qty <= 0) {
                    throw new RefundException('Количество возврата должно быть > 0', 'REFUND_QTY_INVALID');
                }

                $orderItem = OrderItem::query()->withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('order_id', $orderId)
                    ->whereKey($orderItemId)
                    ->lockForUpdate()
                    ->firstOrFail();

                $alreadyRefunded = (float) RefundItem::query()->withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('order_item_id', $orderItemId)
                    ->sum('qty');

                $maxQty = round((float) $orderItem->qty - $alreadyRefunded, 3);
                if ($qty > $maxQty + 0.0001) {
                    throw new RefundException(
                        "Превышено доступное к возврату кол-во по позиции #{$orderItemId}",
                        'REFUND_QTY_EXCEEDED',
                    );
                }

                $lineAmount = round(((float) $orderItem->price * $qty) - ((float) $orderItem->discount * ($qty / max((float) $orderItem->qty, 0.001))), 2);
                $total += $lineAmount;

                RefundItem::query()->withoutGlobalScopes()->forceCreate([
                    'tenant_id' => $tenantId,
                    'refund_id' => $refund->id,
                    'order_item_id' => $orderItemId,
                    'product_id' => $orderItem->product_id,
                    'qty' => $qty,
                    'amount' => $lineAmount,
                    'marking_code' => $orderItem->marking_code,
                ]);

                if ($orderItem->type !== 'service' && $orderItem->product_id !== null) {
                    $this->batches->reverseWriteOff(
                        $tenantId,
                        $orderId,
                        $orderItemId,
                        $qty,
                        $createdBy,
                    );
                }

                if ($orderItem->marking_code) {
                    $this->marking->unbindMarking(
                        $tenantId,
                        (string) $orderItem->marking_code,
                        $orderItem->gtin ? (string) $orderItem->gtin : null,
                    );
                }

                $snapshot = $orderItem->snapshot ?? [];
                $fiscalItems[] = [
                    'name' => $snapshot['name'] ?? ('Позиция #'.$orderItem->id),
                    'price' => (int) round((float) $orderItem->price * 100),
                    'quantity' => $qty,
                    'line_total' => (int) round($lineAmount * 100),
                    'vat_rate' => $orderItem->vat_rate ?? 'none',
                    'marking_code' => $orderItem->marking_code,
                ];
            }

            $receipt = $this->fiscal->createRefundReceipt(
                $tenantId,
                $shiftId ? (int) $shiftId : null,
                $orderId,
                $payment?->id,
                $total,
                $fiscalItems,
                'refund-'.$refund->id.'-'.uniqid(),
            );

            $refund->forceFill([
                'total_amount' => round($total, 2),
                'fiscal_receipt_id' => $receipt->id,
                'status' => $receipt->status === FiscalReceiptStatus::FISCALIZED
                    ? RefundStatusEnum::COMPLETED->value
                    : RefundStatusEnum::PENDING->value,
            ])->save();

            // Order status: full vs partial
            $allItems = OrderItem::query()->withoutGlobalScopes()
                ->where('order_id', $orderId)
                ->get();
            $fullyRefunded = true;
            foreach ($allItems as $oi) {
                $refQty = (float) RefundItem::query()->withoutGlobalScopes()
                    ->where('order_item_id', $oi->id)
                    ->sum('qty');
                if ($refQty + 0.0001 < (float) $oi->qty) {
                    $fullyRefunded = false;
                    break;
                }
            }

            $order->forceFill([
                'status' => $fullyRefunded
                    ? OrderStatusEnum::REFUNDED->value
                    : OrderStatusEnum::PARTIALLY_REFUNDED->value,
                'payment_status' => $fullyRefunded ? 'refund' : ($order->payment_status ?? 'paid'),
            ])->save();

            return $refund->fresh(['items', 'fiscalReceipt']);
        });
    }
}
