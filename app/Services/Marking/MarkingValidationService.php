<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Services\Marking;

use Autometria\Enums\MarkingCodeStatusEnum;
use Autometria\Exceptions\Domain\InvalidMarkingCodeException;
use Autometria\Models\MarkingCode;
use Autometria\Support\AuditLog;
use Illuminate\Support\Facades\DB;

/**
 * Локальный реестр КИЗ + валидация DataMatrix / защита от двойного выбытия.
 */
final class MarkingValidationService
{
    public function __construct(
        private readonly DataMatrixParserService $parser,
    ) {}

    /**
     * Парсинг GS1 DataMatrix + проверка локального статуса (не SOLD / WRITTEN_OFF).
     *
     * @return array{gtin: string, serial: string, crypto_tail: string|null, raw: string, status: string, marking_code_id: int|null}
     *
     * @throws InvalidMarkingCodeException
     */
    public function validateDataMatrix(string $rawCode, ?int $productId = null): array
    {
        $tenantId = (int) (tenant_id() ?? 0);
        abort_unless($tenantId > 0, 422, 'Tenant context required');

        $parsed = $this->parser->parse($rawCode);
        $code = $parsed['raw'];

        return DB::transaction(function () use ($tenantId, $parsed, $code, $productId): array {
            $row = MarkingCode::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('code', $code)
                ->lockForUpdate()
                ->first();

            if ($row !== null) {
                $status = MarkingCodeStatusEnum::tryFrom((string) $row->status);
                if ($status === MarkingCodeStatusEnum::SOLD || $status === MarkingCodeStatusEnum::WRITTEN_OFF) {
                    throw new InvalidMarkingCodeException(
                        'Марка уже выбыла (повторная продажа запрещена)',
                        'MARKING_ALREADY_SOLD',
                    );
                }
            } else {
                $row = MarkingCode::query()->withoutGlobalScopes()->forceCreate([
                    'tenant_id' => $tenantId,
                    'code' => $code,
                    'gtin' => $parsed['gtin'],
                    'serial' => $parsed['serial'],
                    'status' => MarkingCodeStatusEnum::APPLIED->value,
                    'product_id' => $productId,
                ]);
            }

            if ($productId !== null && $row->product_id === null) {
                $row->forceFill(['product_id' => $productId])->save();
            }

            AuditLog::write(
                $tenantId,
                auth()->id(),
                'marking.validated',
                MarkingCode::class,
                (int) $row->id,
                [],
                ['gtin' => $parsed['gtin'], 'serial' => $parsed['serial'], 'status' => $row->status],
            );

            return [
                'gtin' => $parsed['gtin'],
                'serial' => $parsed['serial'],
                'crypto_tail' => $parsed['crypto_tail'],
                'raw' => $code,
                'status' => (string) $row->status,
                'marking_code_id' => (int) $row->id,
            ];
        });
    }

    /**
     * Атомарный перевод марки в SOLD при закрытии чека.
     *
     * @throws InvalidMarkingCodeException
     */
    public function registerMarkSelling(string $code, ?int $receiptId = null, ?int $productId = null): MarkingCode
    {
        $tenantId = (int) (tenant_id() ?? 0);
        abort_unless($tenantId > 0, 422, 'Tenant context required');

        $parsed = $this->parser->parse($code);
        $normalized = $parsed['raw'];

        return DB::transaction(function () use ($tenantId, $normalized, $parsed, $receiptId, $productId): MarkingCode {
            $row = MarkingCode::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('code', $normalized)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                $row = MarkingCode::query()->withoutGlobalScopes()->forceCreate([
                    'tenant_id' => $tenantId,
                    'code' => $normalized,
                    'gtin' => $parsed['gtin'],
                    'serial' => $parsed['serial'],
                    'status' => MarkingCodeStatusEnum::APPLIED->value,
                    'product_id' => $productId,
                ]);
                $row = MarkingCode::query()->withoutGlobalScopes()
                    ->whereKey($row->id)
                    ->lockForUpdate()
                    ->firstOrFail();
            }

            $status = MarkingCodeStatusEnum::tryFrom((string) $row->status);
            if ($status === MarkingCodeStatusEnum::SOLD || $status === MarkingCodeStatusEnum::WRITTEN_OFF) {
                throw new InvalidMarkingCodeException(
                    'Марка уже выбыла (двойное выбытие)',
                    'MARKING_ALREADY_SOLD',
                );
            }

            $row->forceFill([
                'status' => MarkingCodeStatusEnum::SOLD->value,
                'receipt_id' => $receiptId ?? $row->receipt_id,
                'product_id' => $productId ?? $row->product_id,
                'gtin' => $row->gtin ?: $parsed['gtin'],
                'serial' => $row->serial ?: $parsed['serial'],
            ])->save();

            AuditLog::write(
                $tenantId,
                auth()->id(),
                'marking.sold',
                MarkingCode::class,
                (int) $row->id,
                [],
                ['receipt_id' => $receiptId, 'code' => $normalized],
            );

            return $row->fresh();
        });
    }

    /**
     * Возврат марки в оборот (refund unbind).
     */
    public function releaseMarkOnRefund(string $code): void
    {
        $tenantId = (int) (tenant_id() ?? 0);
        if ($tenantId <= 0) {
            return;
        }

        try {
            $parsed = $this->parser->parse($code);
            $normalized = $parsed['raw'];
        } catch (InvalidMarkingCodeException) {
            $normalized = trim($code);
        }

        MarkingCode::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('code', $normalized)
            ->where('status', MarkingCodeStatusEnum::SOLD->value)
            ->update([
                'status' => MarkingCodeStatusEnum::APPLIED->value,
                'receipt_id' => null,
                'updated_at' => now(),
            ]);
    }
}
