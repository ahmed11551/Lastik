<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Jobs;

use Autometria\Events\StockUpdatedEvent;
use Autometria\Jobs\Concerns\SetsTenantContext;
use Autometria\Services\StockBatchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Async FIFO write-off (BcMathDecimal via StockBatchService) on queue stock-reservations.
 */
class ReserveStockJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use SetsTenantContext;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 15, 45];

    public function __construct(
        public int $tenantId,
        public int $warehouseId,
        public int $productId,
        public string $qty,
        public ?int $createdBy = null,
        public ?int $orderId = null,
        public ?int $orderItemId = null,
        public bool $allowOverdraft = false,
    ) {
        $this->onQueue('stock-reservations');
    }

    /**
     * @return array{written_off: mixed, cost: mixed, batches: array, has_overdraft?: bool, shortfall?: mixed}
     */
    public function handle(StockBatchService $batches): array
    {
        $this->bindTenantContext($this->tenantId);

        try {
            $result = $batches->writeOff(
                $this->tenantId,
                $this->warehouseId,
                $this->productId,
                $this->qty,
                $this->createdBy,
                $this->orderId,
                $this->orderItemId,
                $this->allowOverdraft,
            );

            event(new StockUpdatedEvent(
                tenantId: $this->tenantId,
                warehouseId: $this->warehouseId,
                productId: $this->productId,
                writtenOff: (string) ($result['written_off'] ?? $this->qty),
                orderId: $this->orderId,
            ));

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
