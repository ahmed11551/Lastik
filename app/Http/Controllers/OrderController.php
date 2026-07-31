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

namespace Autometria\Http\Controllers;

use Autometria\Exceptions\Domain\InsufficientStockException;
use Autometria\Exceptions\Domain\NoActiveShiftException;
use Autometria\Http\Requests\StoreOrderRequest;
use Autometria\Models\Location;
use Autometria\Models\Order;
use Autometria\Models\OrderItem;
use Autometria\Services\OrderLifecycleService;
use Autometria\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly OrderLifecycleService $lifecycle,
    ) {}

    public function index(Request $request): array
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $tenantId = (int) ($user->tenant_id ?? tenant_id() ?? 0);
        abort_unless($tenantId > 0, 422, 'Tenant context required');

        $locationId = location_id() ?? ($user->location_id ? (int) $user->location_id : null);
        $this->assertLocationBelongsToTenant($tenantId, $locationId);

        $query = Order::query()->where('tenant_id', $tenantId);

        if ($locationId !== null) {
            $query->where('location_id', $locationId);
        }

        return ['data' => $query->latest('id')->get()];
    }

    public function show(Order $order): array
    {
        $this->authorize('view', $order);

        $order->load([
            'orderItems:id,order_id,product_id,type,qty,price,discount,snapshot,kpi_percent,kpi_amount',
            'payments:id,order_id,method,amount,status,payee_id',
            'customer:id,phone,type,legal_name,name',
            'vehicle:id,plate,brand,model',
        ]);

        return [
            'order' => $order,
            'items' => $order->orderItems,
            'payments' => $order->payments->map(fn ($p) => $p->only([
                'id', 'method', 'amount', 'status', 'payee_id', 'created_at',
            ])),
        ];
    }

    public function store(StoreOrderRequest $request): JsonResponse|RedirectResponse
    {
        $this->authorize('create', Order::class);

        try {
            $order = $this->orders->create(
                $request->createOrderDTO(),
                (int) ($request->user()?->id ?? 0),
            );
        } catch (InsufficientStockException $e) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'error' => 'available_less_than_qty',
                ], 409);
            }

            throw $e;
        } catch (NoActiveShiftException $e) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            throw $e;
        }

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json(['data' => $order], 201);
        }

        return redirect()
            ->back()
            ->with('flash', [
                'type' => 'success',
                'message' => 'Заказ создан',
                'order_id' => $order->id,
            ]);
    }

    public function cancel(Request $request, Order $order): JsonResponse
    {
        $this->authorize('cancel', $order);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3'],
            'tenant_id' => ['prohibited'],
            'location_id' => ['prohibited'],
        ]);

        $cancelled = $this->lifecycle->cancel(
            (int) $request->user()->tenant_id,
            (int) $order->id,
            (int) $request->user()->id,
            $validated['reason'],
        );

        return response()->json(['data' => $cancelled]);
    }

    public function destroyItem(Request $request, OrderItem $item): JsonResponse
    {
        // Tenant global scope must stay on — no withoutGlobalScopes().
        $order = Order::query()->findOrFail($item->order_id);
        $this->authorize('update', $order);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3'],
            'tenant_id' => ['prohibited'],
            'location_id' => ['prohibited'],
        ]);

        $this->lifecycle->removeItem(
            (int) $request->user()->tenant_id,
            (int) $item->id,
            (int) $request->user()->id,
            $validated['reason'],
        );

        return response()->json(['ok' => true]);
    }

    private function assertLocationBelongsToTenant(int $tenantId, ?int $locationId): void
    {
        if ($locationId === null) {
            return;
        }

        $belongs = Location::query()
            ->whereKey($locationId)
            ->where('tenant_id', $tenantId)
            ->exists();

        abort_unless($belongs, 403, 'Location does not belong to current tenant');
    }
}
