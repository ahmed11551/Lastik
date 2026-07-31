<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use Exception;
use Throwable;

class InsufficientStockException extends Exception
{
    public function __construct(string $message = 'Недостаточно остатков на складе', int $code = 422, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
