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
use Autometria\Exceptions\Domain\FiscalizationValidationException;
use Autometria\Exceptions\Domain\FiscalNetworkTimeoutException;
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
 * Атомарный Claim-UPDATE + HTTP-фискализация чека (54-ФЗ).
 *
 * 1) Короткая транзакция (CLAIM): SQL UPDATE ... WHERE status IN (PENDING, FAILED_RETRYABLE)
 *    AND (locked_at IS NULL OR locked_at < now() - 2 min) RETURNING *. Если 0 rows —
 *    мгновенный return (другой воркер уже работает / чек фискализирован).
 * 2) HTTP к драйверу ВНЕ транзакции (строго запрещено делать HTTP внутри DB::transaction).
 * 3) Короткая транзакция (ФИНАЛИЗАЦИЯ): success → FISCALIZED; timeout/5xx/unknown →
 *    NEEDS_RECONCILE + dispatch ReconcileReceiptJob; фатальная ошибка валидации → FAILED_FINAL.
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
        // 0) Установка tenant-контекста ДО любых raw-SQL (RLS требует app.current_tenant_id).
        // Иначе под не-superuser ролью (без BYPASSRLS) RLS заблокирует UPDATE ... RETURNING.
        $receiptTenant = FiscalReceipt::query()->withoutGlobalScopes()
            ->whereKey($this->fiscalReceiptId)
            ->value('tenant_id');
        if ($receiptTenant !== null) {
            DB::statement('SELECT set_config(?, ?, true)', [
                'app.current_tenant_id',
                (string) $receiptTenant,
            ]);
        }

        // 1) CLAIM (атомарный capture под блокировкой).
        $claimed = DB::transaction(function () use ($receiptTenant) {
            return DB::selectOne(
                "UPDATE fiscal_receipts
                 SET status = ?, locked_at = clock_timestamp(), attempt = attempt + 1
                 WHERE id = ?
                   AND status IN (?, ?)
                   AND (locked_at IS NULL OR locked_at < clock_timestamp() - interval '2 minutes')
                 RETURNING *",
                [
                    FiscalReceiptStatus::IN_PROGRESS->value,
                    $this->fiscalReceiptId,
                    FiscalReceiptStatus::PENDING->value,
                    FiscalReceiptStatus::FAILED_RETRYABLE->value,
                ]
            );
        });

        // 0 rows → другой воркер уже выполняет или чек финализирован. Мгновенный выход.
        if ($claimed === null) {
            return;
        }

        $receipt = FiscalReceipt::query()->withoutGlobalScopes()->findOrFail($this->fiscalReceiptId);

        try {
            // 2) HTTP к драйверу ВНЕ транзакции.
            $result = $receipt->operation === \Autometria\Enums\FiscalReceiptType::SELL_REFUND
                ? $service->driver()->refund($receipt)
                : $service->driver()->sell($receipt);
        } catch (FiscalNetworkTimeoutException $e) {
            // Сетевой таймаут → NEEDS_RECONCILE (НЕ retryable-sell!). Планируем сверку.
            $this->markNeedsReconcile($receipt, $e->getMessage());

            return;
        }

        // 3) ФИНАЛИЗАЦИЯ.
        if ($result->success) {
            DB::transaction(function () use ($receipt, $result): void {
                $r = FiscalReceipt::query()->withoutGlobalScopes()
                    ->whereKey($receipt->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $r->status = FiscalReceiptStatus::FISCALIZED;
                $r->fn_number = $result->fiscalStorageNumber;
                $r->fd_number = (int) ($result->fiscalDocumentNumber ?? 0);
                $r->fp_value = $result->fiscalSign;
                $r->qr_code_url = $result->qrCodeUrl;
                $r->locked_at = null;
                $r->last_error = null;
                $r->save();

                AuditLog::write(
                    (int) $r->tenant_id,
                    $r->payment?->created_by ?? null,
                    'fiscal.receipt.fiscalized',
                    FiscalReceipt::class,
                    (int) $r->id,
                    [],
                    ['fd' => $result->fiscalDocumentNumber, 'fn' => $result->fiscalStorageNumber],
                );
            });

            return;
        }

        // Сбой, требующий сверки (5xx / unknown ответ ККТ).
        if ($result->needsReconcile) {
            $this->markNeedsReconcile($receipt, $result->errorMessage ?? 'KKT returned retryable/unknown status');

            return;
        }

        // Фатальная ошибка валидации 54-ФЗ → FAILED_FINAL (не retry).
        DB::transaction(function () use ($receipt, $result): void {
            $r = FiscalReceipt::query()->withoutGlobalScopes()
                ->whereKey($receipt->id)
                ->lockForUpdate()
                ->firstOrFail();

            $r->status = FiscalReceiptStatus::FAILED_FINAL;
            $r->last_error = $result->errorMessage;
            $r->locked_at = null;
            $r->save();

            AuditLog::write(
                (int) $r->tenant_id,
                $r->payment?->created_by ?? null,
                'fiscal.receipt.failed_final',
                FiscalReceipt::class,
                (int) $r->id,
                [],
                ['error' => $result->errorMessage],
            );
        });
    }

    private function markNeedsReconcile(FiscalReceipt $receipt, string $message): void
    {
        DB::transaction(function () use ($receipt, $message): void {
            $r = FiscalReceipt::query()->withoutGlobalScopes()
                ->whereKey($receipt->id)
                ->lockForUpdate()
                ->firstOrFail();

            $r->status = FiscalReceiptStatus::NEEDS_RECONCILE;
            $r->last_error = $message;
            $r->save();

            AuditLog::write(
                (int) $r->tenant_id,
                $r->payment?->created_by ?? null,
                'fiscal.receipt.needs_reconcile',
                FiscalReceipt::class,
                (int) $r->id,
                [],
                ['error' => $message],
            );
        });

        // Запланировать асинхронную сверку (отдельная задача ReconcileReceiptJob).
        ReconcileReceiptJob::dispatch($receipt->id);
    }

    public function failed(Throwable $exception): void
    {
        // Исчерпание попыток: если чек остался IN_PROGRESS — снимаем блок для повторного claim.
        DB::transaction(function (): void {
            $r = FiscalReceipt::query()->withoutGlobalScopes()
                ->whereKey($this->fiscalReceiptId)
                ->where('status', FiscalReceiptStatus::IN_PROGRESS->value)
                ->first();

            if ($r !== null) {
                $r->status = FiscalReceiptStatus::FAILED_RETRYABLE;
                $r->locked_at = null;
                $r->last_error = 'Job failed: ' . $exception->getMessage();
                $r->save();
            }
        });
    }
}
