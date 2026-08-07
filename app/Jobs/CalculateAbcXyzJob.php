<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Jobs;

use Autometria\Jobs\Concerns\SetsTenantContext;
use Autometria\Models\Tenant;
use Autometria\Services\Analytics\AbcXyzCalculatorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Persist ABC/XYZ classification matrix (Horizon queue analytics).
 */
class CalculateAbcXyzJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use SetsTenantContext;

    public int $tries = 2;

    /** @var list<int> */
    public array $backoff = [15, 45];

    public function __construct(
        public int $tenantId,
        public int $periodDays = 90,
    ) {
        $this->onQueue('analytics');
    }

    /**
     * @return array{upserted: int, a: int, b: int, c: int, x: int, y: int, z: int}
     */
    public function handle(AbcXyzCalculatorService $service): array
    {
        $this->bindTenantContext($this->tenantId);

        try {
            return $service->calculateForTenant($this->tenantId, $this->periodDays);
        } finally {
            try {
                $this->clearTenantContext();
            } catch (Throwable) {
                // best-effort cleanup
            }
        }
    }

    public function failed(?Throwable $e): void
    {
        report($e);
    }

    /**
     * Dispatch the job for every active tenant (used by the scheduler).
     */
    public static function dispatchForAllTenants(int $periodDays = 90): int
    {
        $tenants = Tenant::query()->where('is_active', true)->pluck('id');
        foreach ($tenants as $tenantId) {
            self::dispatch((int) $tenantId, $periodDays);
        }

        return $tenants->count();
    }
}
