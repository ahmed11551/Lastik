<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Http\Controllers;

use Autometria\Exceptions\Domain\InsufficientStockException;
use Autometria\Services\ProductionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class ProductionController extends Controller
{
    public function __construct(
        private readonly ProductionService $production,
    ) {}

    /**
     * GET /api/v1/production/orders
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = (int) ($request->user()?->tenant_id ?? tenant_id() ?? 0);
        abort_unless($tenantId > 0, 422, 'Tenant context required');

        return response()->json([
            'data' => $this->production->listProductionOrders(
                $tenantId,
                (int) $request->integer('limit', 50),
            ),
        ]);
    }

    /**
     * POST /api/v1/production/produce
     */
    public function produce(Request $request): JsonResponse
    {
        $data = $request->validate([
            'recipe_id' => ['required', 'integer'],
            'qty' => ['required', 'numeric', 'min:0.001'],
            'warehouse_id' => ['required', 'integer'],
        ]);

        try {
            $result = $this->production->produceBatch(
                (int) $data['recipe_id'],
                (float) $data['qty'],
                (int) $data['warehouse_id'],
                (int) ($request->user()?->id ?? 0) ?: null,
            );
        } catch (InsufficientStockException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'InsufficientStockException'], 422);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $result], 201);
    }
}
