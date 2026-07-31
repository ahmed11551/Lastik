<?php

declare(strict_types=1);

use App\Exceptions\Domain\InsufficientStockException;
use App\Exceptions\Domain\TenantAccessDeniedException;

it('throws domain exceptions with default messages', function (): void {
    $stock = new InsufficientStockException;
    expect($stock->getMessage())->toBe('Недостаточно остатков на складе');
    expect($stock->getCode())->toBe(422);

    $tenant = new TenantAccessDeniedException;
    expect($tenant->getMessage())->toBe('Доступ к указанному тенанту запрещен');
    expect($tenant->getCode())->toBe(403);
});
