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
use Autometria\Support\AuditLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Гарантированная фискализация чека (54-ФЗ) с экспоненциальным retry.
 *
 * - $tries = 5, $backoff = [10, 30, 90, 300] (сек) — повтор при сетевых сбоях ОФД.
 * - Запись берётся под lockForUpdate() внутри транзакции.
 * - Если status уже FISCALIZED — пропускаем (идемпотентность по idempotency_key).
 */
class FiscalizeReceiptJob implements ShouldQueue
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
        // 1) Читаем запись под пессимистичной блокировкой (в транзакции).
        $receipt = DB::transaction(function (): FiscalReceipt {
            return FiscalReceipt::query()->withoutGlobalScopes()
                ->whereKey($this->fiscalReceiptId)
                ->lockForUpdate()
                ->firstOrFail();
        });

        // Идемпотентность: уже пробит / возвращён — пропускаем без повторного запроса к ОФД.
        if ($receipt->status === FiscalReceiptStatus::FISCALIZED
            || $receipt->status === FiscalReceiptStatus::REFUNDED) {
            return;
        }

        // 2) Фискализация через драйвер ВНЕ блокирующей транзакции.
        $driver = $service->driver();
        $result = $driver->fiscalize($receipt);

        if ($result->success) {
            DB::transaction(function () use ($receipt, $result): void {
                $r = FiscalReceipt::query()->withoutGlobalScopes()
                    ->whereKey($receipt->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($r->status === FiscalReceiptStatus::FISCALIZED) {
                    return; // кто-то другой уже пробил (конкурентный retry)
                }

                $r->status = FiscalReceiptStatus::FISCALIZED;
                $r->fiscal_document_number = $result->fiscalDocumentNumber;
                $r->fiscal_storage_number = $result->fiscalStorageNumber;
                $r->fiscal_sign = $result->fiscalSign;
                $r->qr_code_url = $result->qrCodeUrl;
                $r->fiscalized_at = now();
                $r->error_message = null;
                $r->save();

                AuditLog::write(
                    (int) $r->tenant_id,
                    $r->payment?->created_by ?? auth()->id(),
                    'fiscal.receipt.fiscalized',
                    FiscalReceipt::class,
                    (int) $r->id,
                    [],
                    ['fd' => $result->fiscalDocumentNumber, 'fn' => $result->fiscalStorageNumber],
                );
            });

            return;
        }

        // 3) Сбой ОФД: фиксируем FAILED (коммитим), затем бросаем — очередь сделает retry по backoff.
        DB::transaction(function () use ($receipt, $result): void {
            $r = FiscalReceipt::query()->withoutGlobalScopes()
                ->whereKey($receipt->id)
                ->lockForUpdate()
                ->firstOrFail();

            $r->status = FiscalReceiptStatus::FAILED;
            $r->error_message = $result->errorMessage;
            $r->attempts = ($r->attempts ?? 0) + 1;
            $r->save();

            AuditLog::write(
                (int) $r->tenant_id,
                $r->payment?->created_by ?? auth()->id(),
                'fiscal.receipt.failed',
                FiscalReceipt::class,
                (int) $r->id,
                [],
                ['error' => $result->errorMessage, 'attempt' => $r->attempts],
            );
        });

        throw new \RuntimeException('Fiscalization failed: ' . $result->errorMessage);
    }

    public function failed(Throwable $exception): void
    {
        // После исчерпания попыток чек остаётся FAILED с error_message (уже записано).
        // Здесь можно уведомить оператора / алерт.
    }
}
