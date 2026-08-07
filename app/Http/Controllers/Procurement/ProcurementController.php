<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Http\Controllers\Procurement;

use Autometria\Http\Controllers\Controller;
use Autometria\Models\PurchaseOrderDraft;
use Autometria\Services\Analytics\DemandForecasterService;
use Autometria\Services\Procurement\AutoOrderGeneratorService;
use Autometria\Services\Procurement\SupplierDispatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ProcurementController extends Controller
{
    public function __construct(
        private readonly AutoOrderGeneratorService $generator,
        private readonly SupplierDispatchService $dispatch,
        private readonly DemandForecasterService $forecaster,
    ) {}

    /**
     * POST /api/v1/procurement/generate-drafts — 1-click draft generation.
     */
    public function generateDrafts(Request $request): JsonResponse
    {
        $lookback = max(1, min(365, (int) ($request->input('lookback_days') ?? 90)));
        $result = $this->generator->generateForTenant($this->tenantId(), $lookback);

        return response()->json(['data' => $result], 201);
    }

    /**
     * GET /api/v1/procurement/drafts — list with filters.
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId();
        $query = PurchaseOrderDraft::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->with('supplier');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($supplierId = $request->query('supplier_id')) {
            $query->where('supplier_id', (int) $supplierId);
        }

        $perPage = max(1, min(200, (int) ($request->query('per_page') ?? 50)));
        $page = max(1, (int) ($request->query('page') ?? 1));
        $total = $query->count();
        $items = $query->orderByDesc('id')->forPage($page, $perPage)->get();

        return response()->json([
            'data' => $items,
            'meta' => ['current_page' => $page, 'per_page' => $perPage, 'total' => $total],
        ]);
    }

    /**
     * POST /api/v1/procurement/drafts/{id}/approve
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        $draft = PurchaseOrderDraft::query()->withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId())
            ->findOrFail($id);

        $draft->update(['status' => 'approved']);

        return response()->json(['data' => $draft->toArray()]);
    }

    /**
     * POST /api/v1/procurement/drafts/{id}/send — email or telegram.
     */
    public function send(Request $request, int $id): JsonResponse
    {
        $channel = $request->input('channel', 'email');
        $sent = match ($channel) {
            'telegram' => $this->dispatch->sendByTelegram($id),
            default => $this->dispatch->sendByEmail($id),
        };

        return response()->json([
            'data' => ['sent' => $sent, 'channel' => $channel],
        ], $sent ? 200 : 422);
    }

    /**
     * GET /api/v1/procurement/drafts/{id}/export.csv
     */
    public function exportCsv(Request $request, int $id): \Illuminate\Http\Response
    {
        $csv = $this->dispatch->exportToCsv($id);

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="purchase_order_'.$id.'.csv"',
        ]);
    }

    private function tenantId(): int
    {
        $id = \tenant_id();

        return $id !== null ? (int) $id : 0;
    }
}
