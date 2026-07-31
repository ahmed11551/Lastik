<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use RuntimeException;

class ShiftAlreadyClosedException extends RuntimeException
{
    public static function default(): self
    {
        return new self('Кассовая смена уже закрыта. Операция запрещена.');
    }
}
