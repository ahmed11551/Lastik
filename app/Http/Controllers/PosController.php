<?php

/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author Себиев Ахмед Сулейманович
 * @license Proprietary & Confidential.
 */

declare(strict_types=1);

namespace Autometria\Http\Controllers;

use Autometria\DTOs\CreateOrderDTO;
use Autometria\Exceptions\Domain\InsufficientStockException;
use Autometria\Exceptions\Domain\NoActiveShiftException;
use Autometria\Models\CashShift;
use Autometria\Models\Location;
use Autometria\Models\ProductService;
use Autometria\Services\OrderService;
use Autometria\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PosController extends Controller
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly PaymentService $payments,
    ) {}

    public function checkout(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $data = $request->validate([
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'vehicle_id' => ['nullable', 'integer', 'exists:vehicles,id'],
            'assigned_seller_id' => ['nullable', 'integer', 'exists:users,id'],
            'method' => ['required', 'string', 'max:40'],
            'amount_tendered' => ['nullable', 'numeric', 'min:0'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products_services,id'],
            'items.*.qty' => ['required', 'numeric', 'min:0.001'],
            'items.*.warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'items.*.discount' => ['nullable', 'numeric', 'min:0'],
            'items.*.type' => ['nullable', 'string', 'in:product,service'],
        ]);

        $tenantId = (int) ($user->tenant_id ?? tenant_id() ?? 0);
        $locationId = (int) (location_id() ?? $user->location_id ?? 0);
        abort_unless($tenantId > 0 && $locationId > 0, 422, 'Tenant/location context required');
        abort_unless(
            Location::query()->whereKey($locationId)->where('tenant_id', $tenantId)->exists(),
            403,
            'Location does not belong to current tenant',
        );

        $shift = CashShift::query()
            ->where('tenant_id', $tenantId)
            ->where('location_id', $locationId)
            ->whereNull('closed_at')
            ->latest('id')
            ->first();

        if ($shift === null) {
            throw new NoActiveShiftException('Нет открытой кассовой смены');
        }

        $items = [];
        foreach ($data['items'] as $row) {
            $product = ProductService::query()->find((int) $row['product_id']);
            $type = $row['type'] ?? ($product?->type === 'service' ? 'service' : 'product');
            $items[] = [
                'type' => $type,
                'product_id' => (int) $row['product_id'],
                'qty' => (float) $row['qty'],
                'discount' => (float) ($row['discount'] ?? 0),
                'warehouse_id' => isset($row['warehouse_id']) ? (int) $row['warehouse_id'] : null,
                'worker_id' => $type === 'service' ? (int) $user->id : null,
            ];
        }

        try {
            $order = DB::transaction(function () use ($data, $items, $tenantId, $locationId, $user, $shift) {
                $order = $this->orders->create(new CreateOrderDTO(
                    tenantId: $tenantId,
                    customerId: isset($data['customer_id']) ? (int) $data['customer_id'] : null,
                    locationId: $locationId,
                    assignedSellerId: (int) ($data['assigned_seller_id'] ?? $user->id),
                    masterId: 0,
                    items: $items,
                    note: 'POS checkout',
                    vehicleId: isset($data['vehicle_id']) ? (int) $data['vehicle_id'] : null,
                    scenario: 'without_installation',
                ), (int) $user->id);

                $total = (float) $order->total;
                $method = (string) $data['method'];
                $tendered = isset($data['amount_tendered']) ? (float) $data['amount_tendered'] : $total;
                if ($method === 'cash' && $tendered + 0.0001 < $total) {
                    abort(422, 'Недостаточно внесённой суммы');
                }

                $this->payments->accept(
                    $tenantId,
                    (int) $order->id,
                    [['method' => $method, 'amount' => $total, 'payee_id' => (int) $user->id]],
                    (int) $user->id,
                    (int) $shift->id,
                );

                return $order->fresh(['orderItems', 'payments']);
            });
        } catch (InsufficientStockException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'InsufficientStockException'], 422);
        } catch (NoActiveShiftException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'NoActiveShiftException'], 422);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $total = (float) $order->total;
        $tendered = isset($data['amount_tendered']) ? (float) $data['amount_tendered'] : $total;
        $change = max(0, round($tendered - $total, 2));

        return response()->json([
            'data' => [
                'order' => $order,
                'total' => $total,
                'tendered' => $tendered,
                'change' => $change,
                'method' => $data['method'],
                'shift_id' => $shift->id,
            ],
        ], 201);
    }
}
