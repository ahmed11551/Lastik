<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @package    Autometria\Exceptions\Domain
 * @copyright  (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author     Себиев Ахмед Сулейманович (Chief Software Architect / Lead Developer)
 * @license    Proprietary & Confidential.
 */

declare(strict_types=1);

namespace Autometria\Exceptions\Domain;

use RuntimeException;

final class ShiftExpiredException extends RuntimeException
{
    public function __construct(string $message = 'Смена превысила 24 часа. Требуется закрытие')
    {
        parent::__construct($message);
    }
}
