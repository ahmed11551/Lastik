<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Services\Marking;

use Autometria\Enums\EgaisDocTypeEnum;
use Autometria\Enums\EgaisDocumentStatusEnum;
use Autometria\Models\EgaisDocument;
use Autometria\Models\ProductService;
use Autometria\Support\AuditLog;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * ЕГАИС — акты вскрытия / списания (локальный реестр документов).
 */
final class EgaisService
{
    /**
     * Акт вскрытия тары (бутылка / кег) для алкогольной продукции.
     */
    public function createEgaisUnsealAct(int $productId, float $volume, string $fsrarId): EgaisDocument
    {
        $tenantId = (int) (tenant_id() ?? 0);
        abort_unless($tenantId > 0, 422, 'Tenant context required');

        if ($volume <= 0) {
            throw new InvalidArgumentException('Volume must be positive');
        }

        $fsrarId = trim($fsrarId);
        if ($fsrarId === '') {
            throw new InvalidArgumentException('FSRAR ID is required');
        }

        $product = ProductService::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereKey($productId)
            ->firstOrFail();

        $doc = EgaisDocument::query()->withoutGlobalScopes()->forceCreate([
            'tenant_id' => $tenantId,
            'doc_type' => EgaisDocTypeEnum::UNSEAL->value,
            'fsrar_id' => $fsrarId,
            'status' => EgaisDocumentStatusEnum::DRAFT->value,
            'payload' => [
                'product_id' => $productId,
                'product_name' => $product->name,
                'egais_alcocode' => $product->egais_alcocode,
                'volume' => round($volume, 3),
                'unit' => 'dal',
                'source' => 'local',
                'sent_to_fsrar' => false,
                'created_at' => now()->toIso8601String(),
            ],
        ]);

        // Если не mock-режим — отправляем акт в ФСРАР (production-ready).
        if (! (bool) config('services.egais.mock_mode', env('EGAIS_MOCK_MODE', true))) {
            $this->sendToFsrar($doc);
        }

        AuditLog::write(
            $tenantId,
            auth()->id(),
            'egais.unseal_created',
            EgaisDocument::class,
            (int) $doc->id,
            [],
            ['fsrar_id' => $fsrarId, 'product_id' => $productId, 'volume' => $volume],
        );

        return $doc;
    }

    /**
     * Отправка акта вскрытия в ФСРАР (ЕГАИС) — production-ready.
     * Переключается флагом EGAIS_MOCK_MODE=false.
     */
    public function sendToFsrar(EgaisDocument $doc): EgaisDocument
    {
        $baseUrl = rtrim((string) config('services.egais.api_url', env('EGAIS_API_URL', 'https://api.egais.ru/api/v2')), '/');
        $fsrarId = config('services.egais.fsrar_id', env('EGAIS_FSRAR_ID', $doc->fsrar_id));
        $certThumbprint = config('services.egais.cert_thumbprint', env('EGAIS_CERT_THUMBPRINT'));

        if ($certThumbprint === null || $certThumbprint === '') {
            throw new InvalidArgumentException('EGAIS_CERT_THUMBPRINT не задан для живого контура ЕГАИС');
        }

        $payload = $doc->payload ?? [];

        $response = Http::timeout((int) env('EGAIS_TIMEOUT', 10))
            ->withHeaders([
                'Content-Type' => 'application/json',
                'fsrar_id' => $fsrarId,
                'cert_thumbprint' => $certThumbprint,
            ])
            ->post("{$baseUrl}/documents/unseal", [
                'fsrar_id' => $fsrarId,
                'doc_id' => $doc->id,
                'product_name' => $payload['product_name'] ?? null,
                'egais_alcocode' => $payload['egais_alcocode'] ?? null,
                'volume' => $payload['volume'] ?? null,
                'unit' => $payload['unit'] ?? 'dal',
            ]);

        if (! $response->successful()) {
            throw new InvalidArgumentException('Ошибка отправки в ФСРАР: HTTP '.$response->status());
        }

        $doc->update([
            'status' => EgaisDocumentStatusEnum::SENT->value,
            'payload' => array_merge($payload, [
                'sent_to_fsrar' => true,
                'fsrar_response' => $response->json(),
                'sent_at' => now()->toIso8601String(),
            ]),
        ]);

        return $doc;
    }
}
