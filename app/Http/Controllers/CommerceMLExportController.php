<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Http\Controllers;

use Autometria\Services\CommerceML\CommerceMLExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

/**
 * CommerceML 2.09 XML export (orders.xml / offers.xml).
 */
final class CommerceMLExportController extends Controller
{
    public function __construct(
        private readonly CommerceMLExportService $export,
    ) {}

    /**
     * GET/POST /api/v1/1c/export/orders — выгрузка заказов в XML.
     */
    public function orders(Request $request): Response|JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $data = $request->validate([
            'since' => ['nullable', 'date'],
            'format' => ['nullable', 'string', 'in:xml,json'],
        ]);

        try {
            $result = $this->export->exportOrders(
                $tenantId,
                isset($data['since']) ? (string) $data['since'] : null,
                'manual_export',
            );
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if (($data['format'] ?? 'xml') === 'json') {
            return response()->json([
                'data' => [
                    'count' => $result['count'],
                    'log_id' => $result['log']->id,
                    'xml' => $result['xml'],
                ],
            ]);
        }

        return response($result['xml'], 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="orders.xml"',
            'X-OneC-Sync-Log-Id' => (string) $result['log']->id,
        ]);
    }

    /**
     * GET/POST /api/v1/1c/export/offers — выгрузка остатков/цен (offers.xml).
     */
    public function offers(Request $request): Response|JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $data = $request->validate([
            'format' => ['nullable', 'string', 'in:xml,json'],
        ]);

        try {
            $result = $this->export->exportOffers($tenantId, 'manual_export');
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if (($data['format'] ?? 'xml') === 'json') {
            return response()->json([
                'data' => [
                    'count' => $result['count'],
                    'log_id' => $result['log']->id,
                    'xml' => $result['xml'],
                ],
            ]);
        }

        return response($result['xml'], 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="offers.xml"',
            'X-OneC-Sync-Log-Id' => (string) $result['log']->id,
        ]);
    }

    private function tenantId(Request $request): int
    {
        $id = (int) ($request->user()?->tenant_id ?? tenant_id() ?? 0);
        abort_unless($id > 0, 422, 'Tenant context required');

        return $id;
    }
}
