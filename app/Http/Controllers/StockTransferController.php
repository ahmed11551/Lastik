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
use Autometria\Models\ProductService;
use Autometria\Models\Warehouse;
use Autometria\Services\PushTriggerService;
use Autometria\Services\StockTransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockTransferController extends Controller
{
    public function __construct(
        private readonly StockTransferService $transfers,
        private readonly PushTriggerService $push,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products_services,id'],
            'from_warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'to_warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'qty' => ['required', 'numeric', 'min:0.001'],
            'reason' => ['required', 'string', 'min:3'],
        ]);

        $tenantId = (int) $request->user()->tenant_id;

        try {
            $transfer = $this->transfers->transfer(
                $tenantId,
                (int) $validated['product_id'],
                (int) $validated['from_warehouse_id'],
                (int) $validated['to_warehouse_id'],
                (float) $validated['qty'],
                $validated['reason'],
                (int) $request->user()->id,
            );
        } catch (InsufficientStockException $e) {
            return response()->json(['message' => $e->getMessage(), 'error' => 'available_less_than_qty'], 409);
        }

        $product = ProductService::query()->withoutGlobalScopes()->find((int) $validated['product_id']);
        $from = Warehouse::query()->withoutGlobalScopes()->find((int) $validated['from_warehouse_id']);
        $to = Warehouse::query()->withoutGlobalScopes()->find((int) $validated['to_warehouse_id']);

        $this->push->stockTransferCreated(
            tenantId: $tenantId,
            productName: $product?->name ?? 'Товар #' . $validated['product_id'],
            fromWarehouse: $from?->name ?? 'Склад #' . $validated['from_warehouse_id'],
            toWarehouse: $to?->name ?? 'Склад #' . $validated['to_warehouse_id'],
            qty: (float) $validated['qty'],
        );

        return response()->json(['data' => $transfer], 201);
    }
}
