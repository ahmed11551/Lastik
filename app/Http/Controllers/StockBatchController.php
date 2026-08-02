<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @package    Autometria\Http\Controllers
 * @copyright  (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author     Себиев Ахмед Сулейманович
 * @license    Proprietary & Confidential.
 */

declare(strict_types=1);

namespace Autometria\Http\Controllers;

use Autometria\Models\Stock;
use Autometria\Models\StockBatch;
use Autometria\Services\StockBatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockBatchController extends Controller
{
    public function __construct(
        private readonly StockBatchService $batches,
    ) {}

    /**
     * FIFO lots for a product on a warehouse.
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products_services,id'],
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
        ]);

        $tenantId = (int) ($request->user()?->tenant_id ?? tenant_id() ?? 0);
        abort_unless($tenantId > 0, 422, 'Tenant context required');

        $lots = StockBatch::query()
            ->where('tenant_id', $tenantId)
            ->where('product_id', (int) $data['product_id'])
            ->where('warehouse_id', (int) $data['warehouse_id'])
            ->where('remaining_qty', '>', 0)
            ->orderBy('received_at', 'asc')
            ->orderBy('id', 'asc')
            ->get()
            ->map(fn (StockBatch $b): array => [
                'id' => $b->id,
                'batch_number' => $b->batch_number ?: ('LOT-'.$b->id),
                'received_at' => optional($b->received_at)?->toIso8601String(),
                'received_date' => optional($b->received_at)?->format('d.m.Y H:i'),
                'qty' => (float) $b->qty,
                'remaining_qty' => (float) $b->remaining_qty,
                'cost_price' => (float) $b->cost_price,
            ])
            ->values();

        return response()->json(['data' => $lots]);
    }

    /**
     * Inventory recount: set factual on-hand qty (generates adjust act via audit).
     */
    public function adjust(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products_services,id'],
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'actual_qty' => ['required', 'numeric', 'min:0'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'stock_id' => ['nullable', 'integer', 'exists:stocks,id'],
        ]);

        $tenantId = (int) ($request->user()?->tenant_id ?? tenant_id() ?? 0);
        abort_unless($tenantId > 0, 422, 'Tenant context required');

        $stock = Stock::query()
            ->where('tenant_id', $tenantId)
            ->where('product_id', (int) $data['product_id'])
            ->where('warehouse_id', (int) $data['warehouse_id'])
            ->first();

        $bookQty = (float) ($stock?->actual ?? 0);
        $actualQty = round((float) $data['actual_qty'], 3);
        $delta = round($actualQty - $bookQty, 3);

        $batch = $this->batches->adjust(
            $tenantId,
            (int) $data['warehouse_id'],
            (int) $data['product_id'],
            $actualQty,
            $data['reason'],
            (int) $request->user()->id,
        );

        $actType = $delta < 0 ? 'write_off' : ($delta > 0 ? 'ingress' : 'none');

        return response()->json([
            'success' => true,
            'data' => [
                'batch_id' => $batch->id,
                'batch_number' => $batch->batch_number,
                'book_qty' => $bookQty,
                'actual_qty' => $actualQty,
                'delta' => $delta,
                'act_type' => $actType,
                'reason' => $data['reason'],
                'message' => match ($actType) {
                    'write_off' => 'Сформирован акт списания',
                    'ingress' => 'Сформирован акт прихода',
                    default => 'Отклонений нет',
                },
            ],
        ]);
    }
}
