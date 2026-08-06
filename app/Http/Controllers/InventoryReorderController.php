<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Http\Controllers;

use Autometria\Jobs\CalculateReorderPointJob;
use Autometria\Models\InventoryReorderRecommendation;
use Autometria\Services\PushTriggerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryReorderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $warehouseId = $request->query('warehouse_id');
        $severity = trim((string) $request->query('severity', ''));
        $deadOnly = filter_var($request->query('dead_stock', false), FILTER_VALIDATE_BOOLEAN);
        $perPage = min(100, max(1, (int) $request->integer('per_page', 50)));

        $query = InventoryReorderRecommendation::query()
            ->with(['product:id,name,article,brand', 'warehouse:id,name'])
            ->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'warn' THEN 1 ELSE 2 END")
            ->orderByDesc('suggested_qty');

        if ($warehouseId !== null && $warehouseId !== '' && $warehouseId !== 'all') {
            $query->where('warehouse_id', (int) $warehouseId);
        }

        if ($severity !== '' && in_array($severity, ['ok', 'warn', 'critical'], true)) {
            $query->where('severity', $severity);
        }

        if ($deadOnly) {
            $query->where('is_dead_stock', true);
        }

        $page = $query->paginate($perPage);

        $criticalCount = InventoryReorderRecommendation::query()
            ->where('severity', 'critical')
            ->when(
                $warehouseId !== null && $warehouseId !== '' && $warehouseId !== 'all',
                fn ($q) => $q->where('warehouse_id', (int) $warehouseId),
            )
            ->count();

        return response()->json([
            'data' => collect($page->items())->map(fn ($r) => $this->serialize($r))->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'critical_count' => $criticalCount,
            ],
        ]);
    }

    public function recalculate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['nullable', 'integer', 'min:1'],
            'lookback_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'lead_time_days' => ['nullable', 'integer', 'min:1', 'max:120'],
            'dead_stock_days' => ['nullable', 'integer', 'min:1', 'max:730'],
            'sync' => ['nullable', 'boolean'],
        ]);

        $tenantId = (int) $request->user()->tenant_id;
        $job = new CalculateReorderPointJob(
            tenantId: $tenantId,
            warehouseId: isset($data['warehouse_id']) ? (int) $data['warehouse_id'] : null,
            lookbackDays: (int) ($data['lookback_days'] ?? 30),
            leadTimeDays: (int) ($data['lead_time_days'] ?? 7),
            deadStockDays: (int) ($data['dead_stock_days'] ?? 90),
        );

        if (! empty($data['sync'])) {
            $result = $job->handle(app(\Autometria\Services\Inventory\InventoryDemandPredictor::class));

            $critical = InventoryReorderRecommendation::query()
                ->where('severity', 'critical')
                ->when(
                    ! empty($data['warehouse_id']),
                    fn ($q) => $q->where('warehouse_id', (int) $data['warehouse_id']),
                )
                ->with(['product:id,name'])
                ->limit(5)
                ->get();

            if ($critical->isNotEmpty()) {
                $push = app(PushTriggerService::class);
                foreach ($critical as $rec) {
                    $push->lowStock(
                        tenantId: $tenantId,
                        productName: $rec->product?->name ?? ('#'. $rec->product_id),
                        available: (float) $rec->on_hand,
                    );
                }
            }

            return response()->json([
                'queued' => false,
                'sync' => true,
                'upserted' => $result['upserted'] ?? 0,
                'critical_count' => $critical->count(),
            ]);
        }

        CalculateReorderPointJob::dispatch(
            $tenantId,
            $job->warehouseId,
            $job->lookbackDays,
            $job->leadTimeDays,
            $job->deadStockDays,
        );

        return response()->json([
            'queued' => true,
            'queue' => 'inventory-reorder',
            'message' => 'Пересчёт точки заказа поставлен в очередь Horizon.',
        ], 202);
    }

    private function serialize(InventoryReorderRecommendation $r): array
    {
        return [
            'id' => $r->id,
            'product_id' => $r->product_id,
            'warehouse_id' => $r->warehouse_id,
            'sku' => (string) ($r->product?->article ?: ''),
            'name' => (string) ($r->product?->name ?: ''),
            'warehouse' => (string) ($r->warehouse?->name ?: ''),
            'd_avg' => (string) $r->d_avg,
            'safety_stock' => (string) $r->safety_stock,
            'rop' => (string) $r->rop,
            'on_hand' => (string) $r->on_hand,
            'suggested_qty' => (string) $r->suggested_qty,
            'is_dead_stock' => (bool) $r->is_dead_stock,
            'severity' => (string) $r->severity,
            'lead_time_days' => (int) $r->lead_time_days,
            'lookback_days' => (int) $r->lookback_days,
            'calculated_at' => $r->calculated_at?->toIso8601String(),
        ];
    }
}
