<?php

declare(strict_types=1);

namespace Autometria\Http\Controllers\Portal;

use Autometria\Http\Controllers\Controller;
use Autometria\Models\Customer;
use Autometria\Models\Order;
use Autometria\Models\Post;
use Autometria\Services\Portal\CustomerPortalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PortalController extends Controller
{
    public function __construct(private readonly CustomerPortalService $portal) {}

    public function me(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->attributes->get('customer');

        return response()->json(['data' => $customer->only([
            'id', 'tenant_id', 'name', 'phone', 'email', 'type', 'bonus_balance', 'tier',
        ])]);
    }

    public function bookings(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->attributes->get('customer');

        return response()->json(['data' => $this->portal->myBookings(
            (int) $customer->tenant_id,
            (int) $customer->id,
        )]);
    }

    public function storeBooking(Request $request): JsonResponse
    {
        $data = $request->validate([
            'post_id' => ['required', 'integer'],
            'start_time' => ['required', 'date'],
            'end_time' => ['required', 'date', 'after:start_time'],
        ]);
        /** @var Customer $customer */
        $customer = $request->attributes->get('customer');
        $booking = $this->portal->bookSlot(
            (int) $customer->tenant_id,
            (int) $customer->id,
            (int) $data['post_id'],
            $data['start_time'],
            $data['end_time'],
        );

        return response()->json(['data' => $booking->load('post:id,name')], 201);
    }

    public function destroyBooking(Request $request, int $id): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->attributes->get('customer');
        $booking = $this->portal->cancelBooking((int) $customer->tenant_id, (int) $customer->id, $id);

        return response()->json(['data' => $booking]);
    }

    public function posts(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->attributes->get('customer');

        return response()->json(['data' => Post::query()->withoutGlobalScopes()
            ->where('tenant_id', $customer->tenant_id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'is_active'])]);
    }

    public function orders(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->attributes->get('customer');

        return response()->json(['data' => Order::query()->withoutGlobalScopes()
            ->where('tenant_id', $customer->tenant_id)
            ->where('customer_id', $customer->id)
            ->latest()
            ->get()]);
    }
}
