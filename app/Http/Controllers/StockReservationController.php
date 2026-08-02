<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Http\Controllers;

use Autometria\Exceptions\Domain\InsufficientStockException;
use Autometria\Services\BranchWarehouseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class StockReservationController extends Controller
{
    public function __construct(
        private readonly BranchWarehouseService $service,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer'],
            'product_id' => ['required', 'integer'],
            'quantity' => ['required', 'numeric', 'min:0.001'],
            'ttl_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $reservation = $this->service->reserveStock(
                $tenantId,
                (int) $data['warehouse_id'],
                (int) $data['product_id'],
                (float) $data['quantity'],
                (int) ($data['ttl_minutes'] ?? 30),
                (int) $request->user()->id,
                $data['reason'] ?? null,
            );
        } catch (InsufficientStockException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'InsufficientStockException'], 422);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => [
                'id' => $reservation->id,
                'warehouse_id' => $reservation->warehouse_id,
                'product_id' => $reservation->product_id,
                'quantity' => (float) $reservation->quantity,
                'reserved_until' => optional($reservation->reserved_until)?->toIso8601String(),
                'status' => $reservation->status,
            ],
        ], 201);
    }

    public function releaseExpired(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $count = $this->service->releaseExpiredReservations($tenantId);

        return response()->json(['data' => ['released' => $count]]);
    }

    private function tenantId(Request $request): int
    {
        $id = (int) ($request->user()?->tenant_id ?? tenant_id() ?? 0);
        abort_unless($id > 0, 422, 'Tenant context required');

        return $id;
    }
}
