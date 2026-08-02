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
use Autometria\Enums\OrderStatusEnum;
use Autometria\Exceptions\Domain\InsufficientStockException;
use Autometria\Exceptions\Domain\InvalidMarkingCodeException;
use Autometria\Exceptions\Domain\NoActiveShiftException;
use Autometria\Exceptions\Domain\PriceNotFoundException;
use Autometria\Models\CashShift;
use Autometria\Models\Location;
use Autometria\Models\ProductService;
use Autometria\Services\OrderService;
use Autometria\Services\PaymentService;
use Autometria\Services\StockBatchService;
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
        private readonly StockBatchService $batches,
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

            // Replay always 200 — no duplicate side effects.
            return response()->json($cached['body'], 200);
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
            'items.*.marking_code' => ['nullable', 'string', 'max:255'],
            'items.*.gtin' => ['nullable', 'string', 'max:14'],
            'items.*.serial_number' => ['nullable', 'string', 'max:64'],
            'uuid' => ['nullable', 'string', 'max:100'],
            'payment_type' => ['nullable', 'string', 'max:20'],
            'shift_id' => ['nullable', 'integer'],
            'total_amount' => ['nullable', 'numeric', 'min:0'],
            'bonus_spend' => ['nullable', 'numeric', 'min:0'],
        ]);

        $tenantId = (int) ($user->tenant_id ?? tenant_id() ?? 0);
        $locationId = (int) (location_id() ?? $user->location_id ?? 0);
        abort_unless($tenantId > 0 && $locationId > 0, 422, 'Tenant/location context required');
        abort_unless(
            Location::query()->whereKey($locationId)->where('tenant_id', $tenantId)->exists(),
            403,
            'Location does not belong to current tenant',
        );

        $isOffline = $offlineUuid !== null;

        $shiftQuery = CashShift::query()
            ->where('tenant_id', $tenantId)
            ->where('location_id', $locationId)
            ->whereNull('closed_at');

        if (! empty($data['shift_id'])) {
            $shift = CashShift::query()
                ->where('tenant_id', $tenantId)
                ->whereKey((int) $data['shift_id'])
                ->first();
        } else {
            $shift = (clone $shiftQuery)->latest('id')->first();
        }

        if ($shift === null) {
            throw new NoActiveShiftException('Нет открытой кассовой смены');
        }

        // 24-hour shift guard (54-ФЗ).
        $expired = $this->isShiftExpired($shift);
        if ($expired) {
            if (! $isOffline) {
                return response()->json([
                    'message' => 'Смена просрочена (превышен 24-часовой лимит)',
                    'code' => 'SHIFT_EXPIRED',
                ], 422);
            }

            // Offline sync of a receipt tied to an expired shift: reject with SHIFT_EXPIRED
            // (UI must open a new shift / Z-report before queue flush).
            return response()->json([
                'message' => 'Смена просрочена (превышен 24-часовой лимит)',
                'code' => 'SHIFT_EXPIRED',
            ], 422);
        }

        $items = [];
        foreach ($data['items'] as $row) {
            $product = ProductService::query()->find((int) $row['product_id']);
            $type = $row['type'] ?? ($product?->type === 'service' ? 'service' : 'product');

            // Regulatory: marked SKU must carry a CIS (Честный Знак / DataMatrix).
            if ($product && (bool) $product->is_marked) {
                $mark = trim((string) ($row['marking_code'] ?? ''));
                if ($mark === '') {
                    return response()->json([
                        'message' => 'Для маркированного товара требуется код DataMatrix (marking_code)',
                        'code' => 'MARKING_CODE_REQUIRED',
                    ], 422);
                }
            }

            $items[] = [
                'type' => $type,
                'product_id' => (int) $row['product_id'],
                'qty' => (float) $row['qty'],
                'discount' => (float) ($row['discount'] ?? 0),
                'warehouse_id' => isset($row['warehouse_id']) ? (int) $row['warehouse_id'] : null,
                'worker_id' => $type === 'service' ? (int) $user->id : null,
                'marking_code' => isset($row['marking_code']) ? (string) $row['marking_code'] : null,
            ];
        }

        $note = $isOffline
            ? 'POS offline sync · '.$offlineUuid
            : 'POS checkout';

        $anyOverdraft = false;
        try {
            $order = DB::transaction(function () use (
                $data, $items, $tenantId, $locationId, $user, $shift, $note, $isOffline, &$anyOverdraft
            ) {
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
                    allowOverdraft: $isOffline,
                ), (int) $user->id);

                $total = (float) $order->total;
                $bonusSpend = (float) ($data['bonus_spend'] ?? 0);
                $cashDue = $total;

                if ($order->customer_id && $bonusSpend > 0) {
                    $settled = app(\Autometria\Services\ReceiptService::class)->create(
                        $tenantId,
                        $order,
                        (int) $order->customer_id,
                        $bonusSpend,
                        $total,
                        null,
                        (int) $user->id,
                    );
                    $cashDue = (float) ($settled['cash_due'] ?? $total);
                    // Avoid double-settle inside PaymentService.
                    $bonusSpend = 0;
                }

                $method = (string) $data['method'];
                $tendered = isset($data['amount_tendered']) ? (float) $data['amount_tendered'] : $cashDue;
                if ($method === 'cash' && $tendered + 0.0001 < $cashDue) {
                    abort(422, 'Недостаточно внесённой суммы');
                }

                if ($cashDue > 0.0001) {
                    $loyaltyCredit = round($total - $cashDue, 2);
                    $this->payments->accept(
                        $tenantId,
                        (int) $order->id,
                        [['method' => $method, 'amount' => $cashDue, 'payee_id' => (int) $user->id]],
                        (int) $user->id,
                        (int) $shift->id,
                        0.0,
                        $loyaltyCredit,
                    );
                } else {
                    $order->forceFill([
                        'payment_status' => 'paid',
                        'locked_at' => now(),
                    ])->save();
                }

                // FIFO write-off. Online → no overdraft; offline → allow overdraft.
                $allowOverdraft = $isOffline;
                foreach ($order->orderItems as $item) {
                    if ($item->type === 'service' || $item->product_id === null) {
                        continue;
                    }
                    $warehouseId = $item->snapshot['warehouse_id']
                        ?? collect($items)->firstWhere('product_id', (int) $item->product_id)['warehouse_id']
                        ?? null;
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
                    if (($result['has_overdraft'] ?? $result['overdraft'] ?? false) === true) {
                        $anyOverdraft = true;
                    }
                }

                if ($anyOverdraft && $isOffline) {
                    $order->forceFill([
                        'status' => OrderStatusEnum::COMPLETED_WITH_OVERDRAFT->value,
                    ])->save();
                }

                return $order->fresh(['orderItems', 'payments']);
            });
        } catch (InsufficientStockException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'InsufficientStockException'], 422);
        } catch (InvalidMarkingCodeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => $e->errorCode,
            ], 422);
        } catch (NoActiveShiftException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'NoActiveShiftException'], 422);
        } catch (PriceNotFoundException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'PriceNotFoundException'], 422);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $total = (float) $order->total;
        $tendered = isset($data['amount_tendered']) ? (float) $data['amount_tendered'] : $total;
        $change = max(0, round($tendered - $total, 2));

        return response()->json([
            'data' => [
                'order' => $order->fresh(),
                'total' => $total,
                'tendered' => $tendered,
                'change' => $change,
                'method' => $data['method'],
                'shift_id' => $shift->id,
                'uuid' => $offlineUuid,
                'has_overdraft' => $anyOverdraft,
            ],
        ], 201);
    }

    private function isShiftExpired(CashShift $shift): bool
    {
        if ($shift->expires_at !== null) {
            return now()->gt($shift->expires_at);
        }
        if ($shift->opened_at !== null) {
            return now()->gt($shift->opened_at->copy()->addHours(24));
        }

        return false;
    }
}
