<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use RuntimeException;

class ShiftAlreadyOpenedException extends RuntimeException
{
    public static function default(): self
    {
        return new self('У кассира уже есть открытая смена.');
    }
}
