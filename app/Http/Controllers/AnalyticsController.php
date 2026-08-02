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

use Autometria\Services\Analytics\AnalyticsReportService;
use Illuminate\Http\Request;

/**
 * REST API аналитики и отчётов COGS (FIFO).
 *
 * Все эндпоинты фильтруют по tenant_id (через EnsureTenant middleware),
 * а также по date_from, date_to, warehouse_id.
 */
final class AnalyticsController
{
    public function __construct(
        private readonly AnalyticsReportService $service,
    ) {}

    public function dashboardSummary(Request $request): \Illuminate\Http\JsonResponse
    {
        $tenantId = $this->tenantId();
        $data = $this->service->getDashboardSummary(
            $tenantId,
            $request->query('date_from'),
            $request->query('date_to'),
            $request->query('warehouse_id') ? (int) $request->query('warehouse_id') : null,
        );

        return response()->json($data);
    }

    public function cogsBreakdown(Request $request): \Illuminate\Http\JsonResponse
    {
        $tenantId = $this->tenantId();
        $data = $this->service->getCogsBreakdown(
            $tenantId,
            $request->query('date_from'),
            $request->query('date_to'),
            $request->query('warehouse_id') ? (int) $request->query('warehouse_id') : null,
        );

        return response()->json($data);
    }

    public function abcXyz(Request $request): \Illuminate\Http\JsonResponse
    {
        $tenantId = $this->tenantId();
        $data = $this->service->getAbcXyzAnalysis(
            $tenantId,
            $request->query('date_from'),
            $request->query('date_to'),
            $request->query('warehouse_id') ? (int) $request->query('warehouse_id') : null,
        );

        return response()->json($data);
    }

    public function turnover(Request $request): \Illuminate\Http\JsonResponse
    {
        $tenantId = $this->tenantId();
        $data = $this->service->getInventoryTurnover(
            $tenantId,
            $request->query('date_from'),
            $request->query('date_to'),
            $request->query('warehouse_id') ? (int) $request->query('warehouse_id') : null,
        );

        return response()->json($data);
    }

    private function tenantId(): int
    {
        $id = \tenant_id();

        return $id !== null ? (int) $id : 0;
    }
}
