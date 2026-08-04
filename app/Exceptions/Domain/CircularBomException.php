<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Exceptions\Domain;

use RuntimeException;
use Throwable;

class CircularBomException extends RuntimeException
{
    public function __construct(string $message = 'Обнаружен цикл или превышена глубина BOM', int $code = 422, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    public static function cycle(int $productId): self
    {
        return new self("Циклическая ссылка в BOM: продукт #{$productId} ссылается на себя транзитивно");
    }

    public static function depthExceeded(int $maxDepth): self
    {
        return new self("Превышена максимальная глубина BOM ({$maxDepth})");
    }
}
