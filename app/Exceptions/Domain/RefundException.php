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

class RefundException extends RuntimeException
{
    public function __construct(
        string $message = 'Ошибка возврата',
        public readonly string $errorCode = 'REFUND_ERROR',
        int $code = 422,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
