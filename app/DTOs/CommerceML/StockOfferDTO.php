<?php

declare(strict_types=1);

namespace App\DTOs\CommerceML;

readonly class StockOfferDTO
{
    public function __construct(
        public int $tenantId,
        public int $warehouseId,
        public string $sku,
        public float $actual,
        public float $reserved,
        public ?float $price = null,
    ) {}
}
