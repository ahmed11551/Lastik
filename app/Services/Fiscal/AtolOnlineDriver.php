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
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Драйвер Атол Онлайн (облачный ОФД / ККМ-агент) по стандарту 54-ФЗ (ФФД 1.2).
 *
 * Строит запрос по тегам ФФД:
 *  - 1054 — признак расчёта (sell / sell_refund / buy / buy_refund)
 *  - 1008 — наименование товара/услуги
 *  - 1227 — ИНН продавца
 *  - 1199/1102 — ставка НДС (тег 1079 — распределение скидок уже в payload)
 *  - 1023 — сумма расчёта
 *
 * driver_request_id передаётся как external_id (идемпотентность на стороне ККТ).
 * При сетевом таймауте / 5xx бросит FiscalNetworkTimeoutException → чек уйдёт
 * в NEEDS_RECONCILE (НЕ в retryable-sell).
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

    public function sell(FiscalReceipt $receipt): FiscalResultDto
    {
        $payload = $this->buildRequest($receipt);

        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->withToken($this->token)
                ->post("{$this->baseUri}/api/v4/{$this->groupCode}/sell", $payload);
        } catch (ConnectionException $e) {
            throw new \Autometria\Exceptions\Domain\FiscalNetworkTimeoutException($e->getMessage());
        }

        // 5xx / unknown — требует сверки, не retryable-sell.
        if ($response->serverError() || $response->status() === 0 || $response->status() >= 500) {
            return FiscalResultDto::failure('ATOL HTTP ' . $response->status(), needsReconcile: true);
        }

        if (! $response->successful()) {
            return FiscalResultDto::failure('ATOL HTTP ' . $response->status() . ': ' . $response->body());
        }

        $data = $response->json();

        return new FiscalResultDto(
            true,
            $data['payload']['fiscal_document_number'] ?? null,
            $data['payload']['fiscal_storage_number'] ?? null,
            $data['payload']['fiscal_sign'] ?? null,
            $data['payload']['qr_code_url'] ?? null,
            $data['uuid'] ?? (string) $receipt->driver_request_id,
        );
    }

    public function checkStatus(string $driverRequestId): FiscalResultDto
    {
        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->withToken($this->token)
                ->get("{$this->baseUri}/api/v4/{$this->groupCode}/report/{$driverRequestId}");
        } catch (ConnectionException $e) {
            throw new \Autometria\Exceptions\Domain\FiscalNetworkTimeoutException($e->getMessage());
        }

        if ($response->status() === 404) {
            return FiscalResultDto::notFound($driverRequestId);
        }

        if (! $response->successful()) {
            return FiscalResultDto::failure('ATOL status HTTP ' . $response->status(), needsReconcile: true);
        }

        $data = $response->json();

        return new FiscalResultDto(
            ($data['status'] ?? '') === 'done',
            $data['payload']['fiscal_document_number'] ?? null,
            $data['payload']['fiscal_storage_number'] ?? null,
            $data['payload']['fiscal_sign'] ?? null,
            $data['payload']['qr_code_url'] ?? null,
            $driverRequestId,
        );
    }

    public function refund(FiscalReceipt $receipt): FiscalResultDto
    {
        $payload = $this->buildRequest($receipt, true);

        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->withToken($this->token)
                ->post("{$this->baseUri}/api/v4/{$this->groupCode}/sell_refund", $payload);
        } catch (ConnectionException $e) {
            throw new \Autometria\Exceptions\Domain\FiscalNetworkTimeoutException($e->getMessage());
        }

        if ($response->status() >= 500) {
            return FiscalResultDto::failure('ATOL HTTP '.$response->status(), needsReconcile: true);
        }

        if (! $response->successful()) {
            return FiscalResultDto::failure('ATOL HTTP '.$response->status().': '.$response->body());
        }

        $data = $response->json() ?? [];

        return new FiscalResultDto(
            ($data['status'] ?? '') === 'done' || ($data['status'] ?? '') === 'wait',
            $data['payload']['fiscal_document_number'] ?? null,
            $data['payload']['fiscal_storage_number'] ?? null,
            $data['payload']['fiscal_sign'] ?? null,
            $data['payload']['qr_code_url'] ?? null,
            (string) $receipt->driver_request_id,
        );
    }

    /**
     * Построить FFД-совместимый запрос из payload_snapshot чека.
     */
    private function buildRequest(FiscalReceipt $receipt, bool $isRefund = false): array
    {
        $items = $receipt->payload_snapshot['items'] ?? [];
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
            $row = [
                'name' => $item['name'] ?? 'Товар',
                'quantity' => (float) ($item['quantity'] ?? 1),
                'price' => (float) (($item['price'] ?? 0) / 100), // minor units -> рубли
                'sum' => (float) (($item['line_total'] ?? $item['price'] ?? 0) / 100),
                'vat' => ['type' => $vat],
                'payment_object' => 'commodity',
                'payment_method' => 'full_payment',
            ];
            // ФФД 1.2 marking: product_code (тег 1162) when CIS present.
            if (! empty($item['product_code'])) {
                $row['product_code'] = $item['product_code'];
            } elseif (! empty($item['fiscal_tags']['1162'])) {
                $row['product_code'] = ['hex' => $item['fiscal_tags']['1162']];
            }
            $receiptItems[] = $row;
        }

        $typeTag = $isRefund
            ? FiscalReceiptType::SELL_REFUND->value
            : ($receipt->operation?->value ?? FiscalReceiptType::SELL->value);

        return [
            'external_id' => (string) $receipt->driver_request_id,   // идемпотентность провайдера
            'receipt' => [
                'client' => ['inn' => $this->inn],
                'company' => [
                    'inn' => $this->inn,
                    'payment_address' => $receipt->payload_snapshot['payment_address'] ?? 'https://autometria.local',
                ],
                'items' => $receiptItems,
                'payments' => [
                    [
                        'type' => 1,
                        'sum' => (float) ($receipt->total_amount ?? 0),
                    ],
                ],
                'total' => (float) ($receipt->total_amount ?? 0),
                'operation_type' => $typeTag,
            ],
        ];
    }
}
