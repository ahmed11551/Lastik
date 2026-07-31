<?php

declare(strict_types=1);

namespace App\DTOs\CommerceML;

/**
 * Элемент каталога CommerceML.
 * sku ↔ article (внутренний артикул); externalId ↔ external_id (ID 1С).
 */
readonly class CatalogItemDTO
{
    public function __construct(
        public int $tenantId,
        public int $warehouseId,
        public string $sku,
        public float $actual,
        public float $reserved,
        public ?float $price = null,
        public ?string $externalId = null,
        public ?string $name = null,
    ) {}

    public function article(): string
    {
        return $this->sku;
    }
}
