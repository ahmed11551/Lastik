<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\CreateOrderDTO;
use App\Exceptions\Domain\InsufficientStockException;
use App\Exceptions\Domain\NoActiveShiftException;
use App\Models\CashShift;
use App\Models\KpiRule;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductService;
use App\Models\Stock;
use App\Support\AuditLog;
use Illuminate\Support\Facades\DB;

final class OrderService
{
    public function __construct(
        private readonly StockReservationService $reservations,
    ) {}

    public function create(CreateOrderDTO $dto, int $createdBy): Order
    {
        return DB::transaction(function () use ($dto, $createdBy): Order {
            app()->instance('current_tenant_id', $dto->tenantId);

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

            $order = Order::query()->withoutGlobalScopes()->create([
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
                $price = (float) $itemPayload['price'];
                $discount = (float) ($itemPayload['discount'] ?? 0);
                $type = (string) ($itemPayload['type'] ?? 'product');

                $product = ProductService::query()
                    ->withoutGlobalScopes()
                    ->where('tenant_id', $dto->tenantId)
                    ->whereKey($productId)
                    ->firstOrFail();

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
                ];

                $orderItem = OrderItem::query()->withoutGlobalScopes()->create([
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
                ]);

                if ($type === 'product') {
                    $stock = Stock::query()
                        ->withoutGlobalScopes()
                        ->where('tenant_id', $dto->tenantId)
                        ->where('product_id', $product->id)
                        ->when(
                            isset($itemPayload['warehouse_id']),
                            fn ($q) => $q->where('warehouse_id', (int) $itemPayload['warehouse_id'])
                        )
                        ->orderByDesc('available')
                        ->lockForUpdate()
                        ->first();

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
}
