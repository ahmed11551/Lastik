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

use Autometria\DTOs\CreateOrderDTO;
use Autometria\Exceptions\Domain\InsufficientStockException;
use Autometria\Exceptions\Domain\NoActiveShiftException;
use Autometria\Exceptions\Domain\PriceNotFoundException;
use Autometria\Models\CashShift;
use Autometria\Models\KpiRule;
use Autometria\Models\Order;
use Autometria\Models\OrderItem;
use Autometria\Models\Price;
use Autometria\Models\ProductService;
use Autometria\Models\Stock;
use Autometria\Services\Marking\EgaisAndMarkingService;
use Autometria\Support\AuditLog;
use Illuminate\Support\Facades\DB;

final class OrderService
{
    public function __construct(
        private readonly StockReservationService $reservations,
        private readonly EgaisAndMarkingService $marking,
    ) {}

    public function create(CreateOrderDTO $dto, int $createdBy): Order
    {
        return DB::transaction(function () use ($dto, $createdBy): Order {
            set_current_tenant_id($dto->tenantId);

            $shift = CashShift::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $dto->tenantId)
                ->where('location_id', $dto->locationId)
                ->where('status', 'opened')
                ->whereNull('closed_at')
                ->latest('id')
                ->first();

            if ($shift === null) {
                // fallback: any open shift for tenant (schema may lack location_id historically)
                $shift = CashShift::query()
                    ->withoutGlobalScopes()
                    ->where('tenant_id', $dto->tenantId)
                    ->where(function ($q): void {
                        $q->where('status', 'opened')->orWhereNull('closed_at');
                    })
                    ->whereNull('closed_at')
                    ->latest('id')
                    ->first();
            }

            if ($shift === null) {
                throw NoActiveShiftException::default();
            }

            $order = Order::query()->withoutGlobalScopes()->forceCreate([
                'tenant_id' => $dto->tenantId,
                'location_id' => $dto->locationId,
                'customer_id' => $dto->customerId,
                'vehicle_id' => $dto->vehicleId,
                'scenario' => $dto->scenario,
                'number' => $this->nextNumber($dto->tenantId),
                'status' => Order::STATUS_CREATED,
                'payment_status' => 'unpaid',
                'shift_id' => $shift->id,
                'assigned_seller_id' => $dto->assignedSellerId ?: null,
                'master_id' => $dto->masterId ?: null,
                'total' => 0,
                'created_by' => $createdBy,
            ]);

            $total = 0.0;

            foreach ($dto->items as $itemPayload) {
                $productId = (int) $itemPayload['product_id'];
                $qty = (float) $itemPayload['qty'];
                // Immutable price lookup: never trust client-supplied price.
                $price = $this->lookupCatalogPrice($dto->tenantId, $productId);
                $discount = (float) ($itemPayload['discount'] ?? 0);
                $type = (string) ($itemPayload['type'] ?? 'product');

                $product = ProductService::query()
                    ->withoutGlobalScopes()
                    ->where('tenant_id', $dto->tenantId)
                    ->whereKey($productId)
                    ->firstOrFail();

                $markingFields = $this->marking->assertValidMarking(
                    $dto->tenantId,
                    $product,
                    isset($itemPayload['marking_code']) ? (string) $itemPayload['marking_code'] : null,
                );

                $kpiRule = KpiRule::query()
                    ->withoutGlobalScopes()
                    ->where('tenant_id', $dto->tenantId)
                    ->where('product_id', $product->id)
                    ->where('is_active', true)
                    ->first();

                $kpiPercent = $kpiRule ? (float) $kpiRule->percent : (float) ($itemPayload['commission_rate'] ?? 0);
                $lineSum = round(($price * $qty) - $discount, 2);
                $kpiAmount = round($lineSum * $kpiPercent / 100, 2);

                $snapshot = [
                    'type' => $type,
                    'name' => $product->name,
                    'brand' => $product->brand,
                    'article' => $product->article,
                    'external_id' => $product->external_id,
                    'qty' => $qty,
                    'price' => $price,
                    'discount' => $discount,
                    'sum' => $lineSum,
                    'kpi_rule' => $kpiRule ? [
                        'id' => $kpiRule->id,
                        'applies_to' => $kpiRule->applies_to,
                        'target_type' => $kpiRule->target_type ?? null,
                        'percent' => $kpiPercent,
                    ] : null,
                    'kpi_percent' => $kpiPercent,
                    'kpi_amount' => $kpiAmount,
                    'added_by' => $createdBy,
                    'assigned_seller_id' => $dto->assignedSellerId ?: null,
                    'master_id' => $dto->masterId ?: null,
                    'added_at' => now()->toIso8601String(),
                    'marking_code' => $markingFields['marking_code'],
                    'gtin' => $markingFields['gtin'],
                    'serial_number' => $markingFields['serial_number'],
                    'is_marked' => (bool) $product->is_marked,
                    'is_egais' => (bool) $product->is_egais,
                ];

                $orderItem = OrderItem::query()->withoutGlobalScopes()->forceCreate([
                    'tenant_id' => $dto->tenantId,
                    'order_id' => $order->id,
                    'product_id' => $product->id,
                    'type' => $type,
                    'qty' => $qty,
                    'price' => $price,
                    'discount' => $discount,
                    'kpi_percent' => $kpiPercent,
                    'kpi_amount' => $kpiAmount,
                    'snapshot' => $snapshot,
                    'marking_code' => $markingFields['marking_code'],
                    'gtin' => $markingFields['gtin'],
                    'serial_number' => $markingFields['serial_number'],
                ]);

                if ($type === 'product') {
                    $warehouseId = isset($itemPayload['warehouse_id'])
                        ? (int) $itemPayload['warehouse_id']
                        : null;

                    $stock = Stock::query()
                        ->withoutGlobalScopes()
                        ->where('tenant_id', $dto->tenantId)
                        ->where('product_id', $product->id)
                        ->when(
                            $warehouseId !== null,
                            fn ($q) => $q->where('warehouse_id', $warehouseId)
                        )
                        ->orderByDesc('available')
                        ->lockForUpdate()
                        ->first();

                    if ($dto->allowOverdraft) {
                        // Offline / fiscal priority: skip reservation, ensure warehouse on snapshot.
                        if ($stock === null && $warehouseId !== null) {
                            $stock = Stock::query()->withoutGlobalScopes()->forceCreate([
                                'tenant_id' => $dto->tenantId,
                                'warehouse_id' => $warehouseId,
                                'product_id' => $product->id,
                                'actual' => 0,
                                'reserved' => 0,
                                'available' => 0,
                            ]);
                        }
                        $snapshot['warehouse_id'] = $stock?->warehouse_id ?? $warehouseId;
                        $orderItem->update(['snapshot' => $snapshot]);
                    } else {
                        if ($stock === null || (float) $stock->available < $qty) {
                            throw new InsufficientStockException('available_less_than_qty');
                        }

                        $this->reservations->reserve(
                            (int) $stock->id,
                            $dto->tenantId,
                            $qty,
                            (int) $orderItem->id,
                        );

                        $snapshot['warehouse_id'] = $stock->warehouse_id;
                        $orderItem->update(['snapshot' => $snapshot]);
                    }
                }

                $total += $lineSum;
            }

            $order->update(['total' => round($total, 2)]);

            AuditLog::write(
                $dto->tenantId,
                $createdBy,
                'order.created',
                Order::class,
                (int) $order->id,
                [],
                [
                    'number' => $order->number,
                    'scenario' => $order->scenario,
                    'total' => $order->total,
                    'items_count' => count($dto->items),
                ],
                ['location_id' => $dto->locationId, 'shift_id' => $shift->id],
            );

            return $order->fresh(['orderItems']);
        });
    }

    private function nextNumber(int $tenantId): string
    {
        $seq = Order::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->count() + 1;

        return sprintf('ORD-%s-%05d', date('Ymd'), $seq);
    }

    /**
     * Каталожная цена из `prices` (retail), без доверия к HTTP payload.
     */
    private function lookupCatalogPrice(int $tenantId, int $productId): float
    {
        $row = Price::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('product_id', $productId)
            ->where(function ($q): void {
                $q->where('type', 'retail')->orWhereNull('type');
            })
            ->orderByDesc('id')
            ->first();

        if ($row === null) {
            throw PriceNotFoundException::forProduct($productId);
        }

        $amount = $row->amount ?? $row->price;

        return round((float) $amount, 2);
    }
}
