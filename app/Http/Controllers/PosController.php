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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PosController extends Controller
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly PaymentService $payments,
        private readonly \Autometria\Services\StockBatchService $batches,
    ) {}

    public function checkout(Request $request): JsonResponse
    {
        return $this->runCheckout($request, null);
    }

    /**
     * Offline-first POS receipt sync (idempotent via X-Idempotency-Key / uuid).
     */
    public function offlineReceipts(Request $request): JsonResponse
    {
        $idempotency = (string) (
            $request->header('X-Idempotency-Key')
            ?: $request->input('uuid')
            ?: ''
        );
        abort_unless($idempotency !== '', 422, 'X-Idempotency-Key / uuid required');

        $cacheKey = 'pos_offline_idem:'.$idempotency;
        if (Cache::has($cacheKey)) {
            /** @var array{status: int, body: array<string, mixed>} $cached */
            $cached = Cache::get($cacheKey);

            return response()->json($cached['body'], (int) ($cached['status'] ?? 200));
        }

        $response = $this->runCheckout($request, $idempotency);
        if ($response->getStatusCode() < 300) {
            Cache::put($cacheKey, [
                'status' => $response->getStatusCode(),
                'body' => $response->getData(true),
            ], now()->addDays(30));
        }

        return $response;
    }

    private function runCheckout(Request $request, ?string $offlineUuid): JsonResponse
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
            'items.*.vat_rate' => ['nullable', 'string', 'max:10'],
            'uuid' => ['nullable', 'string', 'max:100'],
            'payment_type' => ['nullable', 'string', 'max:20'],
            'shift_id' => ['nullable', 'integer'],
            'total_amount' => ['nullable', 'numeric', 'min:0'],
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

        // 24-часовой лимит смены: просроченные смены блокируют синхронизацию.
        if ($shift->expires_at !== null && now()->gt($shift->expires_at)) {
            abort(422, json_encode(['message' => 'Смена просрочена (превышен 24-часовой лимит)', 'code' => 'SHIFT_EXPIRED']));
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

        $note = $offlineUuid
            ? 'POS offline sync · '.$offlineUuid
            : 'POS checkout';

        $anyOverdraft = false;
        try {
            $order = DB::transaction(function () use ($data, $items, $tenantId, $locationId, $user, $shift, $note, $offlineUuid, &$anyOverdraft) {
                $order = $this->orders->create(new CreateOrderDTO(
                    tenantId: $tenantId,
                    customerId: isset($data['customer_id']) ? (int) $data['customer_id'] : null,
                    locationId: $locationId,
                    assignedSellerId: (int) ($data['assigned_seller_id'] ?? $user->id),
                    masterId: 0,
                    items: $items,
                    note: $note,
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

                // Списание партий (FIFO). Для офлайн-чеков разрешён overdraft-фоллбэк
                // (фискальный приоритет: чек уже пробит покупателю в офлайне).
                $allowOverdraft = $offlineUuid !== null;
                $anyOverdraft = false;
                foreach ($order->orderItems as $item) {
                    if ($item->type === 'service' || $item->product_id === null) {
                        continue;
                    }
                    $warehouseId = $item->warehouse_id;
                    if ($warehouseId === null) {
                        continue;
                    }
                    $result = $this->batches->writeOff(
                        $tenantId,
                        (int) $warehouseId,
                        (int) $item->product_id,
                        (float) $item->qty,
                        (int) $user->id,
                        (int) $order->id,
                        (int) $item->id,
                        $allowOverdraft,
                    );
                    if ($result['overdraft'] ?? false) {
                        $anyOverdraft = true;
                    }
                }

                return $order->fresh(['orderItems', 'payments']);
            });
        } catch (InsufficientStockException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'InsufficientStockException'], 422);
        } catch (NoActiveShiftException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'NoActiveShiftException'], 422);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // Офлайн-чек с overdraft: фискальный приоритет — проводим, но помечаем
        // и шлём уведомление кладовщику (инвентаризация / пересорт).
        if ($anyOverdraft && $offlineUuid !== null) {
            $order->forceFill(['status' => \Autometria\Enums\OrderStatusEnum::COMPLETED_WITH_OVERDRAFT->value])->save();

            foreach ($order->orderItems as $item) {
                if ($item->product_id === null) {
                    continue;
                }
                \Autometria\Models\InventoryAlert::query()->withoutGlobalScopes()->forceCreate([
                    'tenant_id' => $tenantId,
                    'product_id' => $item->product_id,
                    'warehouse_id' => $item->warehouse_id,
                    'type' => 'OVERDRAFT',
                    'message' => 'Овердрафт остатка при офлайн-синхронизации: товар #'
                        . $item->product_id . ' (заказ #' . $order->id . ')',
                ]);
            }
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
                'uuid' => $offlineUuid,
            ],
        ], 201);
    }
}
