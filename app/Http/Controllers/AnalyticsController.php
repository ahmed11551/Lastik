<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @package    Autometria\Http\Controllers\Api\V1
 * @copyright  (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author     Себиев Ахмед Сулейманович (Chief Software Architect / Lead Developer)
 * @license    Proprietary & Confidential.
 */

declare(strict_types=1);

namespace Autometria\Http\Controllers;

use Autometria\Jobs\CalculateAbcXyzJob;
use Autometria\Models\ProductClassification;
use Autometria\Services\Analytics\AnalyticsCacheService;
use Autometria\Services\Analytics\AnalyticsReportService;
use Illuminate\Http\Request;

/**
 * REST API аналитики и отчётов COGS (FIFO).
 *
 * Все эндпоинты фильтруют по tenant_id (через EnsureTenant middleware),
 * а также по from/to (или date_from/date_to) и warehouse_id.
 */
final class AnalyticsController
{
    public function __construct(
        private readonly AnalyticsReportService $service,
        private readonly AnalyticsCacheService $cache,
    ) {}

    /**
     * GET /api/v1/analytics/dashboard — сводный отчёт Block 4.1.
     */
    public function dashboard(Request $request): \Illuminate\Http\JsonResponse
    {
        [$from, $to, $warehouseId] = $this->periodFilters($request);
        $topLimit = max(1, min(50, (int) ($request->query('top') ?? 10)));

        $data = $this->cache->getDashboard(
            $this->tenantId(),
            $from,
            $to,
            $warehouseId,
            $topLimit,
        );

        return response()->json(['data' => $data]);
    }

    public function dashboardSummary(Request $request): \Illuminate\Http\JsonResponse
    {
        [$from, $to, $warehouseId] = $this->periodFilters($request);
        $data = $this->cache->getDashboardSummary(
            $this->tenantId(),
            $from,
            $to,
            $warehouseId,
        );

        return response()->json($data);
    }

    public function cogsBreakdown(Request $request): \Illuminate\Http\JsonResponse
    {
        [$from, $to, $warehouseId] = $this->periodFilters($request);
        $data = $this->service->getCogsBreakdown(
            $this->tenantId(),
            $from,
            $to,
            $warehouseId,
        );

        return response()->json($data);
    }

    public function abcXyz(Request $request): \Illuminate\Http\JsonResponse
    {
        $tenantId = $this->tenantId();
        $class = strtoupper((string) $request->query('class', ''));
        $perPage = max(1, min(200, (int) ($request->query('per_page') ?? 50)));
        $page = max(1, (int) ($request->query('page') ?? 1));

        $query = ProductClassification::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId);

        if (preg_match('/^[ABC]$/', $class)) {
            $query->where('abc_class', $class);
        } elseif (preg_match('/^[XYZ]$/', $class)) {
            $query->where('xyz_class', $class);
        } elseif (preg_match('/^[ABC][XYZ]$/', $class)) {
            $query->where('abc_class', $class[0])->where('xyz_class', $class[1]);
        }

        $total = $query->count();
        $items = $query
            ->orderByDesc('revenue_share')
            ->forPage($page, $perPage)
            ->get()
            ->map(function (ProductClassification $c): array {
                return [
                    'product_id' => $c->product_id,
                    'abc_class' => $c->abc_class,
                    'xyz_class' => $c->xyz_class,
                    'revenue_share' => $c->revenue_share,
                    'variation_coefficient' => $c->variation_coefficient,
                    'calculated_at' => $c->calculated_at?->toIso8601String(),
                ];
            });

        return response()->json([
            'data' => $items,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
            ],
        ]);
    }

    /**
     * POST /api/v1/analytics/abc-xyz/recalculate — manual async recompute.
     */
    public function recalculate(Request $request): \Illuminate\Http\JsonResponse
    {
        $periodDays = max(1, min(365, (int) ($request->input('period_days') ?? 90)));
        CalculateAbcXyzJob::dispatch($this->tenantId(), $periodDays);

        return response()->json([
            'data' => ['queued' => true, 'period_days' => $periodDays],
        ], 202);
    }

    /**
     * POST /api/v1/analytics/abc-xyz/recalculate — manual async recompute (Horizon).
     */
    public function recalculateAbcXyz(Request $request): \Illuminate\Http\JsonResponse
    {
        $periodDays = max(1, min(365, (int) ($request->input('period_days') ?? 90)));
        CalculateAbcXyzJob::dispatch($this->tenantId(), $periodDays);

        return response()->json([
            'data' => ['queued' => true, 'period_days' => $periodDays],
        ], 202);
    }

    public function turnover(Request $request): \Illuminate\Http\JsonResponse
    {
        [$from, $to, $warehouseId] = $this->periodFilters($request);
        $data = $this->service->getInventoryTurnover(
            $this->tenantId(),
            $from,
            $to,
            $warehouseId,
        );

        return response()->json($data);
    }

    public function salesSeries(Request $request): \Illuminate\Http\JsonResponse
    {
        [$from, $to, $warehouseId] = $this->periodFilters($request);
        $data = $this->service->getSalesSeries(
            $this->tenantId(),
            $from,
            $to,
            $warehouseId,
        );

        return response()->json(['data' => $data]);
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: ?int}
     */
    private function periodFilters(Request $request): array
    {
        $from = $request->input('from') ?? $request->query('from') ?? $request->input('date_from') ?? $request->query('date_from');
        $to = $request->input('to') ?? $request->query('to') ?? $request->input('date_to') ?? $request->query('date_to');
        $warehouseId = $request->input('warehouse_id') ?? $request->query('warehouse_id');

        return [
            $from !== null && $from !== '' ? (string) $from : null,
            $to !== null && $to !== '' ? (string) $to : null,
            $warehouseId !== null && $warehouseId !== '' ? (int) $warehouseId : null,
        ];
    }

    private function tenantId(): int
    {
        $id = \tenant_id();

        return $id !== null ? (int) $id : 0;
    }
}
