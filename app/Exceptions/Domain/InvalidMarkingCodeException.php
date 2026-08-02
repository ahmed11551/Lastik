<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Exceptions\Domain;

use Exception;
use Throwable;

class InvalidMarkingCodeException extends Exception
{
    public function __construct(
        string $message = 'Недействительный код маркировки',
        public readonly string $errorCode = 'InvalidMarkingCodeException',
        int $code = 422,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public static function required(): self
    {
        return new self(
            'Для маркированного товара требуется код DataMatrix (marking_code)',
            'MARKING_CODE_REQUIRED',
        );
    }
}
