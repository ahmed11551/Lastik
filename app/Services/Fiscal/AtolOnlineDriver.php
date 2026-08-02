<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @package    Autometria\Services\Fiscal
 * @copyright  (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author     Себиев Ахмед Сулейманович (Chief Software Architect / Lead Developer)
 * @license    Proprietary & Confidential.
 */

declare(strict_types=1);

namespace Autometria\Services\Fiscal;

use Autometria\Enums\FiscalReceiptType;
use Autometria\Enums\VatRate;
use Autometria\Models\FiscalReceipt;
use Illuminate\Support\Facades\Http;

/**
 * Драйвер Атол Онлайн (облачный ОФД / ККМ-агент) по стандарту 54-ФЗ (ФФД 1.2).
 *
 * Строит запрос по тегам ФФД:
 *  - 1054 — признак расчёта (sell / sell_refund / buy / buy_refund)
 *  - 1008 — наименование товара/услуги
 *  - 1227 — ИНН пользователя (продавца)
 *  - 1199/1102 — ставка НДС
 *  - 1023 — сумма расчёта
 *
 * В dev/test окружении HTTP-вызовы можно подменять моком HttpClient.
 * Здесь реализован реальный контракт: POST к /api/v4/{group}/sell и парсинг
 * fd/fn/fp из ответа. При сетевом сбое бросает RuntimeException (перехватывается
 * FiscalizeReceiptJob для retry).
 */
final class AtolOnlineDriver implements FiscalDriverInterface
{
    public function __construct(
        private readonly string $groupCode,
        private readonly string $token,
        private readonly string $inn,
        private readonly string $baseUri = 'https://online.atol.ru',
        private readonly int $timeoutSeconds = 15,
    ) {}

    public function fiscalize(FiscalReceipt $receipt): FiscalResultDto
    {
        $payload = $this->buildRequest($receipt);

        $response = Http::timeout($this->timeoutSeconds)
            ->withToken($this->token)
            ->post("{$this->baseUri}/api/v4/{$this->groupCode}/sell", $payload);

        if (! $response->successful()) {
            return FiscalResultDto::failure(
                'ATOL HTTP ' . $response->status() . ': ' . $response->body()
            );
        }

        $data = $response->json();

        return new FiscalResultDto(
            true,
            $data['payload']['fiscal_document_number'] ?? null,
            $data['payload']['fiscal_storage_number'] ?? null,
            $data['payload']['fiscal_sign'] ?? null,
            $data['payload']['qr_code_url'] ?? null,
            $data['uuid'] ?? null,
        );
    }

    public function checkStatus(string $externalId): FiscalResultDto
    {
        $response = Http::timeout($this->timeoutSeconds)
            ->withToken($this->token)
            ->get("{$this->baseUri}/api/v4/{$this->groupCode}/report/{$externalId}");

        if (! $response->successful()) {
            return FiscalResultDto::failure('ATOL status HTTP ' . $response->status());
        }

        $data = $response->json();

        return new FiscalResultDto(
            ($data['status'] ?? '') === 'done',
            $data['payload']['fiscal_document_number'] ?? null,
            $data['payload']['fiscal_storage_number'] ?? null,
            $data['payload']['fiscal_sign'] ?? null,
            $data['payload']['qr_code_url'] ?? null,
            $externalId,
        );
    }

    public function cancel(FiscalReceipt $receipt): bool
    {
        $payload = $this->buildRequest($receipt, true);

        $response = Http::timeout($this->timeoutSeconds)
            ->withToken($this->token)
            ->post("{$this->baseUri}/api/v4/{$this->groupCode}/sell_refund", $payload);

        return $response->successful();
    }

    /**
     * Построить FFД-совместимый запрос из payload чека.
     *
     * @param  array<string, mixed>|null  $override
     */
    private function buildRequest(FiscalReceipt $receipt, bool $isRefund = false): array
    {
        $items = $receipt->payload['items'] ?? [];
        $vatMap = [
            VatRate::VAT_20->value => 'vat20',
            VatRate::VAT_10->value => 'vat10',
            VatRate::VAT_20_120->value => 'vat120',
            VatRate::VAT_10_110->value => 'vat110',
            VatRate::VAT_0->value => 'vat0',
            VatRate::NONE->value => 'none',
        ];

        $receiptItems = [];
        foreach ($items as $item) {
            $vat = $vatMap[$item['vat_rate'] ?? VatRate::NONE->value] ?? 'none';
            $receiptItems[] = [
                'name' => $item['name'] ?? 'Товар',          // тег 1008
                'quantity' => (float) ($item['quantity'] ?? 1),
                'price' => (float) ($item['price'] ?? 0),
                'sum' => (float) ($item['sum'] ?? ($item['price'] ?? 0)),
                'vat' => ['type' => $vat],                    // тег 1199
                'payment_object' => 'commodity',
                'payment_method' => 'full_payment',
            ];
        }

        $typeTag = $isRefund
            ? FiscalReceiptType::SELL_REFUND->value
            : ($receipt->type?->value ?? FiscalReceiptType::SELL->value); // тег 1054

        return [
            'external_id' => $receipt->idempotency_key,       // идемпотентность
            'receipt' => [
                'client' => [
                    'inn' => $this->inn,                       // тег 1227
                ],
                'company' => [
                    'inn' => $this->inn,
                    'payment_address' => $receipt->payload['payment_address'] ?? 'https://autometria.local',
                ],
                'items' => $receiptItems,
                'payments' => [
                    [
                        'type' => 1, // наличными
                        'sum' => (float) ($receipt->payload['total'] ?? 0),
                    ],
                ],
                'total' => (float) ($receipt->payload['total'] ?? 0),
                'operation_type' => $typeTag,                 // тег 1054
            ],
        ];
    }
}
