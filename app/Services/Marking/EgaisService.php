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
                'created_at' => now()->toIso8601String(),
            ],
        ]);

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
}
