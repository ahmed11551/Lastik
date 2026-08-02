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
        [$from, $to, $warehouseId] = $this->periodFilters($request);
        $data = $this->cache->getAbcXyzAnalysis(
            $this->tenantId(),
            $from,
            $to,
            $warehouseId,
        );

        return response()->json($data);
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
        $from = $request->query('from') ?? $request->query('date_from');
        $to = $request->query('to') ?? $request->query('date_to');
        $warehouseId = $request->query('warehouse_id');

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
