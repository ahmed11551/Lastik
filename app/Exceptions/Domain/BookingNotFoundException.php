<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use RuntimeException;

class BookingNotFoundException extends RuntimeException
{
    public static function default(): self
    {
        return new self('Бронь не найдена.');
    }
}
