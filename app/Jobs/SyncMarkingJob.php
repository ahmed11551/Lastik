<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Jobs;

use Autometria\Jobs\Concerns\SetsTenantContext;
use Autometria\Services\Marking\ChestnyZnakClient;
use Autometria\Services\Marking\MarkingValidationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Async Честный Знак / marking sync with retries (queue: marking-sync).
 * Local CIS registry update stays optional via $registerSold.
 */
class SyncMarkingJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use SetsTenantContext;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 30, 90, 180, 300];

    public function __construct(
        public int $tenantId,
        public string $markingCode,
        public ?int $productId = null,
        public ?int $receiptId = null,
        public bool $registerSold = false,
        /** @var array{gtin?: string, serial?: string, crypto_tail?: string|null, raw?: string}|null */
        public ?array $parsed = null,
    ) {
        $this->onQueue('marking-sync');
    }

    /**
     * @return array{status: mixed, payload: array<string, mixed>}|null
     */
    public function handle(
        ChestnyZnakClient $chestny,
        MarkingValidationService $marking,
    ): ?array {
        $this->bindTenantContext($this->tenantId);

        try {
            $parsed = $this->parsed;
            if ($parsed === null || ! isset($parsed['gtin'], $parsed['serial'], $parsed['raw'])) {
                $parsed = $marking->validateDataMatrix($this->markingCode, $this->productId);
            }

            /** @var array{gtin: string, serial: string, crypto_tail?: string|null, raw: string} $forClient */
            $forClient = [
                'gtin' => (string) ($parsed['gtin'] ?? ''),
                'serial' => (string) ($parsed['serial'] ?? ''),
                'crypto_tail' => $parsed['crypto_tail'] ?? null,
                'raw' => (string) ($parsed['raw'] ?? $this->markingCode),
            ];

            $result = $chestny->validate($this->markingCode, $forClient);

            if ($this->registerSold) {
                $marking->registerMarkSelling(
                    $this->markingCode,
                    $this->receiptId,
                    $this->productId,
                );
            }

            return $result;
        } finally {
            $this->clearTenantContext();
        }
    }

    public function failed(Throwable $exception): void
    {
        $this->clearTenantContext();
    }
}
