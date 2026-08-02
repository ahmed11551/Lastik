<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @package    Autometria\Http\Controllers
 * @copyright  (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Http\Controllers;

use Autometria\Models\ImportJob;
use Autometria\Models\OneCSyncLog;
use Autometria\Services\CommerceML\CommerceMLExportService;
use Autometria\Services\Import\CommerceMLImportService;
use Autometria\Services\OneC\OneCSyncLogger;
use Autometria\Services\OneC\OneCSyncSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Throwable;

class OneCSyncController extends Controller
{
    public function __construct(
        private readonly OneCSyncSettingsService $settings,
        private readonly CommerceMLImportService $imports,
        private readonly CommerceMLExportService $export,
        private readonly OneCSyncLogger $logger,
    ) {}

    public function credentials(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);

        return response()->json(['data' => $this->settings->getPublicCredentials($tenantId)]);
    }

    public function resetCredentials(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $result = $this->settings->resetCredentials($tenantId);

        return response()->json([
            'data' => array_merge(
                $this->settings->getPublicCredentials($tenantId),
                [
                    'password' => $result['password'],
                    'password_hint' => $result['password_hint'],
                ],
            ),
            'message' => 'Новый пароль сгенерирован. Скопируйте его сейчас — повторно он не отобразится.',
        ]);
    }

    public function updateOptions(Request $request): JsonResponse
    {
        $data = $request->validate([
            'update_stocks' => ['required', 'boolean'],
            'update_prices' => ['required', 'boolean'],
            'create_products' => ['required', 'boolean'],
            'sync_mode' => ['nullable', 'string', 'in:manual,auto'],
            'remote_url' => ['nullable', 'string', 'max:500'],
        ]);

        $tenantId = $this->tenantId($request);
        $options = $this->settings->updateOptions($tenantId, $data);

        return response()->json(['data' => ['options' => $options]]);
    }

    /**
     * GET /api/v1/1c/sync-logs — реестр OneCSyncLog (inbound + outbound).
     */
    public function syncLogs(Request $request): JsonResponse
    {
        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'status' => ['nullable', 'string', 'in:pending,processing,done,failed,Success,Error'],
            'direction' => ['nullable', 'string', 'in:inbound,outbound'],
        ]);

        $tenantId = $this->tenantId($request);
        $perPage = (int) ($data['per_page'] ?? 20);

        $q = OneCSyncLog::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('id');

        if (! empty($data['direction'])) {
            $q->where('direction', $data['direction']);
        }
        if (! empty($data['status'])) {
            $status = match ($data['status']) {
                'Success' => 'done',
                'Error' => 'failed',
                default => $data['status'],
            };
            $q->where('status', $status);
        }

        $paginator = $q->paginate($perPage, ['*'], 'page', (int) ($data['page'] ?? 1));

        return response()->json([
            'data' => collect($paginator->items())->map(fn (OneCSyncLog $l) => [
                'id' => $l->id,
                'direction' => $l->direction ?? 'inbound',
                'channel' => $l->channel ?? 'exchange',
                'file_name' => $l->file_name,
                'status' => $l->status === 'done' ? 'Success' : ($l->status === 'failed' ? 'Error' : $l->status),
                'processed_count' => (int) $l->processed_count,
                'payload_size' => (int) ($l->payload_size ?? 0),
                'errors' => $l->errors,
                'details' => $l->details ?? [],
                'created_at' => optional($l->created_at)?->toIso8601String(),
                'updated_at' => optional($l->updated_at)?->toIso8601String(),
            ])->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * POST /api/v1/1c/push — ручная выгрузка заказов (XML) + лог.
     */
    public function push(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);

        try {
            $orders = $this->export->exportOrders($tenantId, null, 'manual_push');
            $offers = $this->export->exportOffers($tenantId, 'manual_push');
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => [
                'orders' => [
                    'count' => $orders['count'],
                    'log_id' => $orders['log']->id,
                    'bytes' => strlen($orders['xml']),
                ],
                'offers' => [
                    'count' => $offers['count'],
                    'log_id' => $offers['log']->id,
                    'bytes' => strlen($offers['xml']),
                ],
            ],
            'message' => 'Выгрузка в 1С сформирована',
        ]);
    }

    /**
     * POST /api/v1/1c/pull — запросить импорт (ожидает файл в multipart или подтверждает готовность).
     */
    public function pull(Request $request): JsonResponse
    {
        if ($request->hasFile('file')) {
            return $this->manualUpload($request);
        }

        $tenantId = $this->tenantId($request);
        $creds = $this->settings->getPublicCredentials($tenantId);
        $log = $this->logger->start($tenantId, 'pull.request', 'inbound', 'manual_pull');
        $this->logger->succeed($log, 0, 0, [
            'ready' => true,
            'exchange_url' => $creds['exchange_url'],
        ]);

        return response()->json([
            'data' => [
                'ready' => true,
                'log_id' => $log->id,
                'exchange_url' => $creds['exchange_url'],
                'hint' => 'Загрузите XML через manual-upload или инициируйте обмен 1С → exchange_url',
            ],
        ]);
    }

    public function logs(Request $request): JsonResponse
    {
        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'status' => ['nullable', 'string', 'in:pending,processing,completed,finished,failed'],
            'channel' => ['nullable', 'string', 'in:auto_1c,manual_upload'],
        ]);

        $tenantId = $this->tenantId($request);
        $perPage = (int) ($data['per_page'] ?? 20);

        $q = ImportJob::query()
            ->where('tenant_id', $tenantId)
            ->where('source', 'commerceml2')
            ->orderByDesc('id');

        if (! empty($data['status'])) {
            $status = $data['status'];
            if ($status === 'completed') {
                $q->whereIn('status', ['completed', 'finished']);
            } else {
                $q->where('status', $status);
            }
        }
        if (! empty($data['channel'])) {
            $q->where('channel', $data['channel']);
        }

        $paginator = $q->paginate($perPage, ['*'], 'page', (int) ($data['page'] ?? 1));

        return response()->json([
            'data' => collect($paginator->items())->map(fn (ImportJob $j) => $this->serializeJob($j))->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function manualUpload(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:51200'],
            'type' => ['nullable', 'string', 'in:import,offers,auto'],
        ]);

        $tenantId = $this->tenantId($request);
        $uploaded = $request->file('file');
        $original = (string) $uploaded->getClientOriginalName();
        $type = (string) ($request->input('type') ?: $this->detectType($original));

        $ext = strtolower((string) $uploaded->getClientOriginalExtension());
        abort_unless($ext === 'xml', 422, 'Принимаются только файлы .xml');

        $storedPath = $uploaded->storeAs(
            '1c/manual/'.$tenantId,
            uniqid($type.'_', true).'.xml',
        );
        $absolute = Storage::path($storedPath);

        try {
            $job = $this->imports->import(
                $absolute,
                $tenantId,
                (int) $request->user()->id,
                [
                    'file_name' => $original !== '' ? $original : ($type.'.xml'),
                    'channel' => 'manual_upload',
                    'file_type' => $type,
                ],
            );
        } catch (Throwable $e) {
            $failed = ImportJob::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('channel', 'manual_upload')
                ->where('status', 'failed')
                ->latest('id')
                ->first();

            if ($failed === null) {
                $failed = ImportJob::query()->withoutGlobalScopes()->forceCreate([
                    'tenant_id' => $tenantId,
                    'source' => 'commerceml2',
                    'file_name' => $original,
                    'channel' => 'manual_upload',
                    'status' => 'failed',
                    'summary' => ['processed' => 0, 'file_type' => $type],
                    'errors' => [['message' => $e->getMessage()]],
                    'error_message' => $e->getMessage(),
                    'created_by' => (int) $request->user()->id,
                ]);
            }

            return response()->json(['data' => $this->serializeJob($failed), 'message' => $e->getMessage()], 422);
        } finally {
            Storage::delete($storedPath);
        }

        return response()->json(['data' => $this->serializeJob($job)], 201);
    }

    private function tenantId(Request $request): int
    {
        $tenantId = (int) ($request->user()?->tenant_id ?? tenant_id() ?? 0);
        abort_unless($tenantId > 0, 422, 'Tenant context required');

        return $tenantId;
    }

    private function detectType(string $name): string
    {
        $lower = strtolower($name);
        if (str_contains($lower, 'offer')) {
            return 'offers';
        }
        if (str_contains($lower, 'import')) {
            return 'import';
        }

        return 'auto';
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeJob(ImportJob $j): array
    {
        $summary = is_array($j->summary) ? $j->summary : [];
        $status = $this->normalizeStatus((string) $j->status);

        $categories = (int) ($summary['categories'] ?? $summary['groups'] ?? 0);
        $products = (int) ($summary['products'] ?? $summary['created'] ?? 0)
            + (int) ($summary['updated'] ?? 0);
        $offers = (int) ($summary['offers'] ?? $summary['processed'] ?? 0);

        $errorMessage = $j->error_message;
        if (! $errorMessage && is_array($j->errors) && $j->errors !== []) {
            $first = $j->errors[0];
            $errorMessage = is_array($first)
                ? (string) ($first['message'] ?? json_encode($first, JSON_UNESCAPED_UNICODE))
                : (string) $first;
        }

        return [
            'id' => $j->id,
            'source' => $j->source,
            'channel' => $j->channel ?: 'manual_upload',
            'file_name' => $j->file_name ?: ($summary['file_name'] ?? null),
            'status' => $status,
            'summary' => $summary,
            'objects' => [
                'categories' => $categories,
                'products' => $products,
                'offers' => $offers,
                'processed' => (int) ($summary['processed'] ?? $offers),
                'conflicts' => (int) ($summary['conflicts'] ?? 0),
                'skipped' => (int) ($summary['skipped'] ?? 0),
            ],
            'error_message' => $errorMessage,
            'errors' => $j->errors ?? [],
            'created_at' => optional($j->created_at)?->toIso8601String(),
            'updated_at' => optional($j->updated_at)?->toIso8601String(),
        ];
    }

    private function normalizeStatus(string $status): string
    {
        return match ($status) {
            'finished', 'completed', 'finished_with_errors' => 'completed',
            'processing', 'pending' => $status === 'pending' ? 'pending' : 'processing',
            'failed' => 'failed',
            default => $status,
        };
    }
}
