<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\Domain\InsufficientStockException;
use App\Exceptions\Domain\NoActiveShiftException;
use App\Http\Requests\StoreOrderRequest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\OrderLifecycleService;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly OrderLifecycleService $lifecycle,
    ) {}

    public function index(): array
    {
        $query = Order::query();

        $locationId = location_id();
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
        $order = Order::query()->withoutGlobalScopes()->findOrFail($item->order_id);
        $this->authorize('update', $order);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3'],
        ]);

        $this->lifecycle->removeItem(
            (int) $request->user()->tenant_id,
            (int) $item->id,
            (int) $request->user()->id,
            $validated['reason'],
        );

        return response()->json(['ok' => true]);
    }
}
