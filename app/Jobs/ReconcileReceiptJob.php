<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @package    Autometria\Jobs
 * @copyright  (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author     Себиев Ахмед Сулейманович (Chief Software Architect / Lead Developer)
 * @license    Proprietary & Confidential.
 */

declare(strict_types=1);

namespace Autometria\Jobs;

use Autometria\Enums\FiscalReceiptStatus;
use Autometria\Models\FiscalReceipt;
use Autometria\Services\Fiscal\FiscalReceiptService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Сверка чека, попавшего в NEEDS_RECONCILE (сетевой таймаут/5xx/unknown).
 *
 * Делает GET /status через $driver->checkStatus($receipt->driver_request_id):
 *  - найден в ККТ → ФД/ФН/ФП + статус FISCALIZED.
 *  - не существует → FAILED_RETRYABLE (можно повторить sell с тем же driver_request_id).
 */
class ReconcileReceiptJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    /** @var array<int> */
    public array $backoff = [10, 30, 90, 300];

    public function __construct(
        public int $fiscalReceiptId,
    ) {}

    public function handle(FiscalReceiptService $service): void
    {
        $receipt = FiscalReceipt::query()->withoutGlobalScopes()->findOrFail($this->fiscalReceiptId);

        if ($receipt->status !== FiscalReceiptStatus::NEEDS_RECONCILE) {
            return; // уже финализирован кем-то другим
        }

        $result = $service->driver()->checkStatus((string) $receipt->driver_request_id);

        DB::transaction(function () use ($receipt, $result): void {
            $r = FiscalReceipt::query()->withoutGlobalScopes()
                ->whereKey($receipt->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($result->success) {
                $r->status = FiscalReceiptStatus::FISCALIZED;
                $r->fn_number = $result->fiscalStorageNumber;
                $r->fd_number = (int) ($result->fiscalDocumentNumber ?? 0);
                $r->fp_value = $result->fiscalSign;
                $r->qr_code_url = $result->qrCodeUrl;
                $r->last_error = null;
                $r->save();

                return;
            }

            // Документ с этим driver_request_id в ККТ не существует → можно retry sell.
            if ($result->notFound) {
                $r->status = FiscalReceiptStatus::FAILED_RETRYABLE;
                $r->last_error = 'Reconcile: document not found in KKT, reschedule sell';
                $r->save();

                return;
            }

            // Иначе остаёмся в NEEDS_RECONCILE (unknown) — повторная сверка по backoff.
            $r->last_error = $result->errorMessage ?? 'Reconcile: unknown status';
            $r->save();
        });
    }

    public function failed(Throwable $exception): void
    {
        // После исчерпания попыток чек остаётся NEEDS_RECONCILE — требуется ручная сверка.
    }
}
