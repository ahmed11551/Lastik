<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use RuntimeException;

class NoActiveShiftException extends RuntimeException
{
    public static function default(): self
    {
        return new self('Нет открытой смены для закрытия.');
    }
}
