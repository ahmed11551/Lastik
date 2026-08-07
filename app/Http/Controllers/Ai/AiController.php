<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Http\Controllers\Ai;

use Autometria\Http\Controllers\Controller;
use Autometria\Services\Ai\AirLlmBridgeService;
use Autometria\Services\Analytics\AnalyticsReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AiController extends Controller
{
    public function __construct(
        private readonly AirLlmBridgeService $bridge,
        private readonly AnalyticsReportService $analytics,
    ) {}

    /**
     * POST /api/v1/ai/nlp-search — текстовый запрос кассира/менеджера.
     */
    public function nlpSearch(Request $request): JsonResponse
    {
        $prompt = (string) $request->input('prompt', '');
        if ($prompt === '') {
            return response()->json(['error' => 'prompt required'], 422);
        }

        $result = $this->bridge->parseNaturalQuery($prompt);

        return response()->json([
            'data' => [
                'filters' => $result['filters'],
                'interpretation' => $result['interpretation'],
                'source' => $result['source'],
            ],
        ]);
    }

    /**
     * GET /api/v1/ai/daily-summary — сформированное ИИ-резюме за день.
     */
    public function dailySummary(Request $request): JsonResponse
    {
        $date = (string) ($request->query('date') ?? now()->subDay()->toDateString());
        $tenantId = $this->tenantId();

        $metrics = $this->analytics->getDashboardSummary($tenantId, $date, $date, null);
        $result = $this->bridge->generateExecutiveSummary($metrics);

        return response()->json([
            'data' => [
                'date' => $date,
                'text' => $result['text'],
                'source' => $result['source'],
                'model' => $result['model'],
            ],
        ]);
    }

    private function tenantId(): int
    {
        $id = \tenant_id();

        return $id !== null ? (int) $id : 0;
    }
}
