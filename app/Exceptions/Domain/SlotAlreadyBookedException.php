<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use RuntimeException;

class SlotAlreadyBookedException extends RuntimeException
{
    public static function default(): self
    {
        return new self('Выбранный слот уже занят.');
    }
}
