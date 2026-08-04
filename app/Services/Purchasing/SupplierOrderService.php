<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Services\Purchasing;

use Autometria\Enums\SupplierOrderStatusEnum;
use Autometria\Models\DeliverySchedule;
use Autometria\Models\ProductService;
use Autometria\Models\Stock;
use Autometria\Models\StockBatch;
use Autometria\Models\Supplier;
use Autometria\Models\SupplierOrder;
use Autometria\Models\SupplierOrderItem;
use Autometria\Models\Warehouse;
use Autometria\Services\Wms\SerialNumberService;
use Autometria\Services\Wms\StorageCellService;
use Autometria\Support\AuditLog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class SupplierOrderService
{
    public function __construct(
        private readonly StorageCellService $cells,
        private readonly SerialNumberService $serials,
    ) {}

    /**
     * @param  array{
     *   supplier_id: int,
     *   warehouse_id: int,
     *   order_date?: string|null,
     *   expected_delivery?: string|null,
     *   note?: string|null,
     *   items: list<array{product_id: int, qty: float|int, unit_price: float|int, planned_delivery?: string|null}>
     * }  $data
     */
    public function createOrder(int $tenantId, array $data, ?int $createdBy = null): SupplierOrder
    {
        $supplierId = (int) ($data['supplier_id'] ?? 0);
        $warehouseId = (int) ($data['warehouse_id'] ?? 0);
        $items = $data['items'] ?? [];

        if ($supplierId <= 0 || $warehouseId <= 0) {
            throw new InvalidArgumentException('supplier_id and warehouse_id are required');
        }
        if (! is_array($items) || $items === []) {
            throw new InvalidArgumentException('Order items are required');
        }

        $this->assertSupplier($tenantId, $supplierId);
        $this->assertWarehouse($tenantId, $warehouseId);

        return DB::transaction(function () use ($tenantId, $data, $supplierId, $warehouseId, $items, $createdBy): SupplierOrder {
            set_current_tenant_id($tenantId);

            $total = 0.0;
            foreach ($items as $row) {
                $qty = (float) ($row['qty'] ?? 0);
                $price = (float) ($row['unit_price'] ?? 0);
                if ($qty <= 0 || $price < 0 || (int) ($row['product_id'] ?? 0) <= 0) {
                    throw new InvalidArgumentException('Invalid order item');
                }
                $total += round($qty * $price, 2);
            }

            $order = SupplierOrder::query()->withoutGlobalScopes()->forceCreate([
                'tenant_id' => $tenantId,
                'supplier_id' => $supplierId,
                'warehouse_id' => $warehouseId,
                'status' => SupplierOrderStatusEnum::DRAFT->value,
                'order_date' => $data['order_date'] ?? now()->toDateString(),
                'expected_delivery' => $data['expected_delivery'] ?? null,
                'total_amount' => round($total, 2),
                'note' => $data['note'] ?? null,
            ]);

            foreach ($items as $row) {
                SupplierOrderItem::query()->withoutGlobalScopes()->forceCreate([
                    'tenant_id' => $tenantId,
                    'supplier_order_id' => $order->id,
                    'product_id' => (int) $row['product_id'],
                    'qty' => round((float) $row['qty'], 3),
                    'received_qty' => 0,
                    'unit_price' => round((float) $row['unit_price'], 2),
                    'planned_delivery' => $row['planned_delivery'] ?? $data['expected_delivery'] ?? null,
                ]);
            }

            AuditLog::write(
                $tenantId,
                $createdBy ?? auth()->id(),
                'purchases.order.created',
                SupplierOrder::class,
                (int) $order->id,
                [],
                [
                    'supplier_id' => $supplierId,
                    'warehouse_id' => $warehouseId,
                    'total_amount' => round($total, 2),
                    'items_count' => count($items),
                ],
            );

            return $order->fresh(['items', 'supplier', 'warehouse']) ?? $order;
        });
    }

    public function confirmOrder(int $tenantId, int $orderId, ?int $userId = null): SupplierOrder
    {
        return DB::transaction(function () use ($tenantId, $orderId, $userId): SupplierOrder {
            set_current_tenant_id($tenantId);

            $order = SupplierOrder::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereKey($orderId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($order->status !== SupplierOrderStatusEnum::DRAFT->value) {
                throw new InvalidArgumentException('Only DRAFT orders can be confirmed');
            }

            $order->forceFill(['status' => SupplierOrderStatusEnum::CONFIRMED->value])->save();

            $items = SupplierOrderItem::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('supplier_order_id', $order->id)
                ->get();

            DeliverySchedule::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('supplier_order_id', $order->id)
                ->delete();

            foreach ($items as $item) {
                DeliverySchedule::query()->withoutGlobalScopes()->forceCreate([
                    'tenant_id' => $tenantId,
                    'supplier_order_id' => $order->id,
                    'product_id' => $item->product_id,
                    'planned_date' => $item->planned_delivery ?? $order->expected_delivery ?? now()->toDateString(),
                    'qty' => $item->qty,
                ]);
            }

            AuditLog::write(
                $tenantId,
                $userId ?? auth()->id(),
                'purchases.order.confirmed',
                SupplierOrder::class,
                (int) $order->id,
                ['status' => SupplierOrderStatusEnum::DRAFT->value],
                ['status' => SupplierOrderStatusEnum::CONFIRMED->value, 'schedules' => $items->count()],
            );

            return $order->fresh(['items', 'deliverySchedules', 'supplier', 'warehouse']) ?? $order;
        });
    }

    /**
     * @param  list<array{
     *   product_id?: int,
     *   supplier_order_item_id?: int,
     *   qty: float|int,
     *   cost_price?: float|int,
     *   storage_cell_id?: int|null,
     *   serials?: list<string>
     * }>  $items
     */
    public function receiveGoods(int $tenantId, int $orderId, array $items, ?int $userId = null): SupplierOrder
    {
        if ($items === []) {
            throw new InvalidArgumentException('Receive items are required');
        }

        return DB::transaction(function () use ($tenantId, $orderId, $items, $userId): SupplierOrder {
            set_current_tenant_id($tenantId);

            $order = SupplierOrder::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereKey($orderId)
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($order->status, [
                SupplierOrderStatusEnum::CONFIRMED->value,
                SupplierOrderStatusEnum::PARTIALLY_RECEIVED->value,
            ], true)) {
                throw new InvalidArgumentException('Order must be CONFIRMED or PARTIALLY_RECEIVED to receive goods');
            }

            $warehouseId = (int) $order->warehouse_id;
            if ($warehouseId <= 0) {
                throw new InvalidArgumentException('Order warehouse_id is required');
            }

            $orderItems = SupplierOrderItem::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('supplier_order_id', $order->id)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $byProduct = $orderItems->keyBy('product_id');
            $receivedTrace = [];

            foreach ($items as $row) {
                $qty = round((float) ($row['qty'] ?? 0), 3);
                if ($qty <= 0) {
                    throw new InvalidArgumentException('Receive qty must be positive');
                }

                $item = null;
                if (! empty($row['supplier_order_item_id'])) {
                    $item = $orderItems->get((int) $row['supplier_order_item_id']);
                } elseif (! empty($row['product_id'])) {
                    $item = $byProduct->get((int) $row['product_id']);
                }

                if ($item === null) {
                    throw new InvalidArgumentException('Order item not found for receive line');
                }

                $remaining = round((float) $item->qty - (float) $item->received_qty, 3);
                if ($qty > $remaining + 0.0001) {
                    throw new InvalidArgumentException('Receive qty exceeds ordered remaining');
                }

                $cost = array_key_exists('cost_price', $row)
                    ? round((float) $row['cost_price'], 2)
                    : round((float) $item->unit_price, 2);

                $stock = Stock::query()->withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('warehouse_id', $warehouseId)
                    ->where('product_id', $item->product_id)
                    ->lockForUpdate()
                    ->first();

                if ($stock === null) {
                    $stock = Stock::query()->withoutGlobalScopes()->forceCreate([
                        'tenant_id' => $tenantId,
                        'warehouse_id' => $warehouseId,
                        'product_id' => $item->product_id,
                        'actual' => 0,
                        'reserved' => 0,
                        'available' => 0,
                    ]);
                    $stock = Stock::query()->withoutGlobalScopes()
                        ->whereKey($stock->id)
                        ->lockForUpdate()
                        ->firstOrFail();
                }

                $batch = StockBatch::query()->withoutGlobalScopes()->forceCreate([
                    'tenant_id' => $tenantId,
                    'warehouse_id' => $warehouseId,
                    'product_id' => $item->product_id,
                    'supplier_order_id' => $order->id,
                    'batch_number' => 'PO-'.$order->id.'-'.$item->id.'-'.now()->format('YmdHis'),
                    'qty' => $qty,
                    'remaining_qty' => $qty,
                    'cost_price' => $cost,
                    'received_at' => now(),
                    'is_overdraft' => false,
                ]);

                $stock->actual = round((float) $stock->actual + $qty, 3);
                $stock->available = round((float) $stock->actual - (float) $stock->reserved, 3);
                $stock->save();

                $item->received_qty = round((float) $item->received_qty + $qty, 3);
                $item->save();

                if (! empty($row['storage_cell_id'])) {
                    $this->cells->placeBatch(
                        $tenantId,
                        (int) $batch->id,
                        (int) $row['storage_cell_id'],
                        $qty,
                        $userId,
                    );
                }

                if (! empty($row['serials']) && is_array($row['serials'])) {
                    $this->serials->receive(
                        $tenantId,
                        (int) $item->product_id,
                        (int) $batch->id,
                        $row['serials'],
                        $warehouseId,
                        $userId,
                    );
                }

                $receivedTrace[] = [
                    'item_id' => $item->id,
                    'product_id' => $item->product_id,
                    'qty' => $qty,
                    'cost_price' => $cost,
                    'batch_id' => $batch->id,
                    'storage_cell_id' => $row['storage_cell_id'] ?? null,
                    'serials_count' => is_array($row['serials'] ?? null) ? count($row['serials']) : 0,
                ];
            }

            $freshItems = SupplierOrderItem::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('supplier_order_id', $order->id)
                ->get();

            $allReceived = $freshItems->every(
                fn (SupplierOrderItem $i) => (float) $i->received_qty + 0.0001 >= (float) $i->qty
            );
            $anyReceived = $freshItems->contains(
                fn (SupplierOrderItem $i) => (float) $i->received_qty > 0
            );

            $newStatus = $allReceived
                ? SupplierOrderStatusEnum::RECEIVED->value
                : ($anyReceived
                    ? SupplierOrderStatusEnum::PARTIALLY_RECEIVED->value
                    : $order->status);

            $order->forceFill(['status' => $newStatus])->save();

            AuditLog::write(
                $tenantId,
                $userId ?? auth()->id(),
                'purchases.order.received',
                SupplierOrder::class,
                (int) $order->id,
                [],
                [
                    'status' => $newStatus,
                    'warehouse_id' => $warehouseId,
                    'lines' => $receivedTrace,
                ],
            );

            return $order->fresh(['items', 'supplier', 'warehouse']) ?? $order;
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function planReplenishment(int $tenantId, int $warehouseId): array
    {
        $this->assertWarehouse($tenantId, $warehouseId);

        $products = ProductService::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where(function ($q): void {
                $q->where('type', ProductService::TYPE_PRODUCT)->orWhereNull('type');
            })
            ->where('is_active', true)
            ->whereNotNull('min_stock')
            ->where('min_stock', '>', 0)
            ->get(['id', 'name', 'article', 'min_stock', 'max_stock', 'reorder_point', 'base_price']);

        $stocks = Stock::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('warehouse_id', $warehouseId)
            ->whereIn('product_id', $products->pluck('id'))
            ->get(['product_id', 'available', 'actual', 'reserved'])
            ->keyBy('product_id');

        $plan = [];
        foreach ($products as $product) {
            $stock = $stocks->get($product->id);
            $available = $stock ? (float) $stock->available : 0.0;
            $min = (float) $product->min_stock;
            $threshold = $product->reorder_point !== null
                ? (float) $product->reorder_point
                : $min;

            if ($available + 0.0001 >= $threshold) {
                continue;
            }

            $target = $product->max_stock !== null ? (float) $product->max_stock : $min;
            if ($target < $min) {
                $target = $min;
            }
            $suggested = round(max(0, $target - $available), 3);
            if ($suggested <= 0) {
                continue;
            }

            $plan[] = [
                'product_id' => (int) $product->id,
                'name' => $product->name,
                'article' => $product->article,
                'warehouse_id' => $warehouseId,
                'available' => round($available, 3),
                'min_stock' => round($min, 3),
                'max_stock' => $product->max_stock !== null ? round((float) $product->max_stock, 3) : null,
                'reorder_point' => round($threshold, 3),
                'suggested_qty' => $suggested,
                'unit_price' => round((float) ($product->base_price ?? 0), 2),
            ];
        }

        usort($plan, fn ($a, $b) => $a['available'] <=> $b['available']);

        return $plan;
    }

    /**
     * @return Collection<int, SupplierOrder>
     */
    public function listOrders(int $tenantId, ?string $status = null): Collection
    {
        return SupplierOrder::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->when($status, fn ($q) => $q->where('status', $status))
            ->with(['supplier', 'warehouse', 'items.product'])
            ->orderByDesc('id')
            ->get();
    }

    public function createSupplier(int $tenantId, array $data): Supplier
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('Supplier name is required');
        }

        return Supplier::query()->withoutGlobalScopes()->forceCreate([
            'tenant_id' => $tenantId,
            'name' => $name,
            'inn' => $data['inn'] ?? null,
            'contact_person' => $data['contact_person'] ?? null,
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'address' => $data['address'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);
    }

    /**
     * @return Collection<int, Supplier>
     */
    public function listSuppliers(int $tenantId, bool $activeOnly = false): Collection
    {
        return Supplier::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->when($activeOnly, fn ($q) => $q->where('is_active', true))
            ->orderBy('name')
            ->get();
    }

    private function assertSupplier(int $tenantId, int $supplierId): void
    {
        $ok = Supplier::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereKey($supplierId)
            ->exists();
        if (! $ok) {
            throw new InvalidArgumentException('Supplier not found for tenant');
        }
    }

    private function assertWarehouse(int $tenantId, int $warehouseId): void
    {
        $ok = Warehouse::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereKey($warehouseId)
            ->exists();
        if (! $ok) {
            throw new InvalidArgumentException('Warehouse not found for tenant');
        }
    }
}
