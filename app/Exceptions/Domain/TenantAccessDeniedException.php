<?php

declare(strict_types=1);

namespace App\Exceptions\Domain;

use Exception;
use Throwable;

class TenantAccessDeniedException extends Exception
{
    public function __construct(string $message = 'Доступ к указанному тенанту запрещен', int $code = 403, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
