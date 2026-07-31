<?php

/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author Себиев Ахмед Сулейманович
 * @license Proprietary & Confidential.
 */

declare(strict_types=1);

use Autometria\Exceptions\Domain\InsufficientStockException;
use Autometria\Exceptions\Domain\TenantAccessDeniedException;

it('throws domain exceptions with default messages', function (): void {
    $stock = new InsufficientStockException;
    expect($stock->getMessage())->toBe('Недостаточно остатков на складе');
    expect($stock->getCode())->toBe(422);

    $tenant = new TenantAccessDeniedException;
    expect($tenant->getMessage())->toBe('Доступ к указанному тенанту запрещен');
    expect($tenant->getCode())->toBe(403);
});
