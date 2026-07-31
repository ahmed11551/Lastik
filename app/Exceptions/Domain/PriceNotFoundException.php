<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use RuntimeException;

class PriceNotFoundException extends RuntimeException
{
    public static function forProduct(int $productId): self
    {
        return new self("Прайс не найден для товара #{$productId}");
    }
}
