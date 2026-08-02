<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Services;

/**
 * Резолвинг цены: warehouse_product_prices → prices.retail/base → product.base_price.
 */
final class PriceResolverService
{
    public function __construct(
        private readonly BranchWarehouseService $branches,
    ) {}

    public function resolve(int $tenantId, int $productId, ?int $warehouseId = null): float
    {
        return $this->branches->resolveProductPrice($tenantId, $productId, $warehouseId);
    }
}
