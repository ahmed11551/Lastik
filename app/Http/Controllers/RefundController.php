<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Http\Controllers;

use Autometria\Exceptions\Domain\InsufficientStockException;
use Autometria\Exceptions\Domain\RefundException;
use Autometria\Models\Order;
use Autometria\Services\RefundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RefundController extends Controller
{
    public function __construct(
        private readonly RefundService $refunds,
    ) {}

    /**
     * POST /api/v1/pos/refunds
     * POST /api/v1/orders/{order}/refunds
     */
    public function store(Request $request, ?Order $order = null): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $data = $request->validate([
            'order_id' => ['nullable', 'integer', 'exists:orders,id'],
            'reason' => ['nullable', 'string', 'max:255'],
            'cash_shift_id' => ['nullable', 'integer'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.order_item_id' => ['required', 'integer', 'exists:order_items,id'],
            'items.*.qty' => ['required', 'numeric', 'min:0.001'],
        ]);

        $tenantId = (int) ($user->tenant_id ?? tenant_id() ?? 0);
        abort_unless($tenantId > 0, 422, 'Tenant context required');

        $routeOrder = $request->route('order');
        $orderId = $order?->id
            ?? (is_object($routeOrder) ? (int) $routeOrder->id : (int) $routeOrder)
            ?: (int) ($data['order_id'] ?? 0);
        abort_unless($orderId > 0, 422, 'order_id required');

        try {
            $refund = $this->refunds->refundOrder(
                $tenantId,
                $orderId,
                $data['items'],
                (int) $user->id,
                $data['reason'] ?? null,
                isset($data['cash_shift_id']) ? (int) $data['cash_shift_id'] : null,
            );
        } catch (RefundException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => $e->errorCode,
            ], 422);
        } catch (InsufficientStockException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'InsufficientStockException',
            ], 422);
        }

        return response()->json([
            'data' => [
                'refund' => $refund,
                'fiscal_receipt' => $refund->fiscalReceipt,
            ],
        ], 201);
    }
}
