<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Http\Controllers;

use Autometria\Services\CommerceML\CommerceMLExportService;
use Autometria\Services\Import\CommerceMLImportService;
use Autometria\Services\OneC\OneCSyncLogger;
use Autometria\Services\OneC\OneCSyncSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Near-real-time JSON push/pull for 1C / external ERP.
 */
final class JsonExchangeController extends Controller
{
    public function __construct(
        private readonly CommerceMLExportService $export,
        private readonly CommerceMLImportService $imports,
        private readonly OneCSyncSettingsService $settings,
        private readonly OneCSyncLogger $logger,
    ) {}

    /**
     * POST /api/v1/1c/json/push — выгрузка заказов и остатков JSON «на лету».
     */
    public function push(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);

        try {
            $snapshot = $this->export->pushSnapshot($tenantId, 'json_push');
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => [
                'orders' => $snapshot['orders'],
                'offers' => $snapshot['offers'],
                'log_id' => $snapshot['log']->id,
                'generated_at' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * POST /api/v1/1c/json/pull — принять входящий пакет (остатки) и залогировать.
     */
    public function pull(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $data = $request->validate([
            'payload' => ['nullable', 'array'],
            'xml' => ['nullable', 'string'],
            'file_name' => ['nullable', 'string', 'max:255'],
        ]);

        $log = $this->logger->start(
            $tenantId,
            (string) ($data['file_name'] ?? 'pull.json'),
            'inbound',
            'json_pull',
        );

        try {
            if (! empty($data['xml'])) {
                $path = '1c/pull/'.$tenantId.'/'.uniqid('pull_', true).'.xml';
                Storage::put($path, (string) $data['xml']);
                $absolute = Storage::path($path);
                $job = $this->imports->import(
                    $absolute,
                    $tenantId,
                    (int) ($request->user()?->id ?? 0) ?: null,
                    [
                        'file_name' => $data['file_name'] ?? 'pull.xml',
                        'channel' => 'json_pull',
                        'file_type' => 'offers',
                    ],
                );
                Storage::delete($path);
                $this->logger->succeed($log, (int) ($job->summary['processed'] ?? 0), strlen((string) $data['xml']), [
                    'import_job_id' => $job->id,
                ]);

                return response()->json(['data' => ['log_id' => $log->id, 'import_job_id' => $job->id]]);
            }

            // Acknowledge JSON payload receipt (counterparty / status hooks).
            $bytes = strlen((string) json_encode($data['payload'] ?? [], JSON_UNESCAPED_UNICODE));
            $this->logger->succeed($log, is_array($data['payload'] ?? null) ? count($data['payload']) : 0, $bytes, [
                'acknowledged' => true,
                'keys' => array_keys($data['payload'] ?? []),
            ]);

            return response()->json(['data' => ['log_id' => $log->id, 'acknowledged' => true]]);
        } catch (Throwable $e) {
            $this->logger->fail($log, $e);

            return response()->json(['message' => $e->getMessage(), 'log_id' => $log->id], 422);
        }
    }

    private function tenantId(Request $request): int
    {
        $id = (int) ($request->user()?->tenant_id ?? tenant_id() ?? 0);
        abort_unless($id > 0, 422, 'Tenant context required');

        return $id;
    }
}
