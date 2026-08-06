<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Jobs;

use Autometria\Jobs\Concerns\SetsTenantContext;
use Autometria\Models\InventoryReorderRecommendation;
use Autometria\Services\Inventory\InventoryDemandPredictor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Persist AI reorder recommendations (Horizon queue inventory-reorder).
 */
class CalculateReorderPointJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use SetsTenantContext;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 30, 90];

    public function __construct(
        public int $tenantId,
        public ?int $warehouseId = null,
        public int $lookbackDays = 30,
        public int $leadTimeDays = 7,
        public int $deadStockDays = 90,
    ) {
        $this->onQueue('inventory-reorder');
    }

    /**
     * @return array{upserted: int}
     */
    public function handle(InventoryDemandPredictor $predictor): array
    {
        $this->bindTenantContext($this->tenantId);

        try {
            $rows = $predictor->predict(
                $this->tenantId,
                $this->warehouseId,
                $this->lookbackDays,
                $this->leadTimeDays,
                $this->deadStockDays,
            );

            $now = now();
            $upserted = 0;

            DB::transaction(function () use ($rows, $now, &$upserted): void {
                foreach ($rows as $row) {
                    InventoryReorderRecommendation::query()->withoutGlobalScopes()->updateOrCreate(
                        [
                            'tenant_id' => $this->tenantId,
                            'warehouse_id' => $row['warehouse_id'],
                            'product_id' => $row['product_id'],
                        ],
                        [
                            'd_avg' => $row['d_avg'],
                            'safety_stock' => $row['safety_stock'],
                            'rop' => $row['rop'],
                            'on_hand' => $row['on_hand'],
                            'suggested_qty' => $row['suggested_qty'],
                            'is_dead_stock' => $row['is_dead_stock'],
                            'severity' => $row['severity'],
                            'lead_time_days' => $row['lead_time_days'],
                            'lookback_days' => $row['lookback_days'],
                            'calculated_at' => $now,
                        ],
                    );
                    $upserted++;
                }
            });

            return ['upserted' => $upserted];
        } finally {
            try {
                $this->clearTenantContext();
            } catch (Throwable) {
                // Aborted PG transaction during tests — cleanup is best-effort.
            }
        }
    }

    public function failed(?Throwable $e): void
    {
        report($e);
    }
}
