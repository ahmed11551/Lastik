<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Http\Controllers\Purchasing;

use Autometria\Exceptions\Domain\InsufficientStockException;
use Autometria\Http\Controllers\Controller;
use Autometria\Models\SupplierOrder;
use Autometria\Services\Purchasing\SupplierOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class SupplierOrderController extends Controller
{
    public function __construct(
        private readonly SupplierOrderService $purchasing,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $status = $request->query('status') ? (string) $request->query('status') : null;

        $rows = $this->purchasing->listOrders($tenantId, $status)->map(fn (SupplierOrder $o) => $this->serialize($o));

        return response()->json(['data' => $rows->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $data = $request->validate([
            'supplier_id' => ['required', 'integer'],
            'warehouse_id' => ['required', 'integer'],
            'order_date' => ['nullable', 'date'],
            'expected_delivery' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.qty' => ['required', 'numeric', 'min:0.001'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.planned_delivery' => ['nullable', 'date'],
        ]);

        try {
            $order = $this->purchasing->createOrder(
                $tenantId,
                $data,
                (int) ($request->user()?->id ?? 0) ?: null,
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->serialize($order)], 201);
    }

    public function confirm(Request $request, int $id): JsonResponse
    {
        $tenantId = $this->tenantId($request);

        try {
            $order = $this->purchasing->confirmOrder(
                $tenantId,
                $id,
                (int) ($request->user()?->id ?? 0) ?: null,
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->serialize($order)]);
    }

    public function receive(Request $request, int $id): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.supplier_order_item_id' => ['nullable', 'integer'],
            'items.*.product_id' => ['nullable', 'integer'],
            'items.*.qty' => ['required', 'numeric', 'min:0.001'],
            'items.*.cost_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        try {
            $order = $this->purchasing->receiveGoods(
                $tenantId,
                $id,
                $data['items'],
                (int) ($request->user()?->id ?? 0) ?: null,
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (InsufficientStockException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->serialize($order)]);
    }

    public function replenishmentPlan(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer'],
        ]);

        try {
            $plan = $this->purchasing->planReplenishment($tenantId, (int) $data['warehouse_id']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $plan]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(SupplierOrder $order): array
    {
        $order->loadMissing(['supplier', 'warehouse', 'items.product', 'deliverySchedules']);

        return [
            'id' => $order->id,
            'supplier_id' => $order->supplier_id,
            'supplier_name' => $order->supplier?->name,
            'warehouse_id' => $order->warehouse_id,
            'warehouse_name' => $order->warehouse?->name,
            'status' => $order->status,
            'order_date' => optional($order->order_date)?->toDateString(),
            'expected_delivery' => optional($order->expected_delivery)?->toDateString(),
            'total_amount' => round((float) $order->total_amount, 2),
            'note' => $order->note,
            'items' => $order->items->map(fn ($i) => [
                'id' => $i->id,
                'product_id' => $i->product_id,
                'product_name' => $i->product?->name,
                'article' => $i->product?->article,
                'qty' => round((float) $i->qty, 3),
                'received_qty' => round((float) $i->received_qty, 3),
                'unit_price' => round((float) $i->unit_price, 2),
                'planned_delivery' => optional($i->planned_delivery)?->toDateString(),
            ])->values(),
            'schedules_count' => $order->deliverySchedules->count(),
        ];
    }

    private function tenantId(Request $request): int
    {
        $id = (int) ($request->user()?->tenant_id ?? tenant_id() ?? 0);
        abort_unless($id > 0, 422, 'Tenant context required');

        return $id;
    }
}
