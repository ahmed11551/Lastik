<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Services\OneC;

use Autometria\Models\OneCSyncLog;
use Throwable;

/**
 * Unified writer for one_c_sync_logs (inbound import + outbound export).
 */
final class OneCSyncLogger
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function start(
        int $tenantId,
        string $fileName,
        string $direction = 'outbound',
        string $channel = 'manual_export',
    ): OneCSyncLog {
        return OneCSyncLog::query()->withoutGlobalScopes()->forceCreate([
            'tenant_id' => $tenantId,
            'direction' => $direction,
            'channel' => $channel,
            'file_name' => $fileName,
            'status' => 'processing',
            'processed_count' => 0,
            'payload_size' => 0,
            'errors' => null,
            'details' => [],
        ]);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public function succeed(OneCSyncLog $log, int $processed, int $payloadSize, array $details = []): OneCSyncLog
    {
        $log->forceFill([
            'status' => 'done',
            'processed_count' => $processed,
            'payload_size' => $payloadSize,
            'details' => $details,
            'errors' => null,
        ])->save();

        return $log->fresh();
    }

    public function fail(OneCSyncLog $log, Throwable|string $error, array $details = []): OneCSyncLog
    {
        $message = $error instanceof Throwable ? $error->getMessage() : $error;
        $log->forceFill([
            'status' => 'failed',
            'errors' => $message,
            'details' => array_merge($details, ['error' => $message]),
        ])->save();

        return $log->fresh();
    }
}
