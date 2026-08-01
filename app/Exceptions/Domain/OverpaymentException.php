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

final class OverpaymentException extends RuntimeException
{
    public function __construct(
        float $orderTotal,
        float $attempted,
        string $message = 'Payment amount exceeds order total (overpayment not allowed)',
    ) {
        parent::__construct(sprintf('%s [total=%.2f, attempted=%.2f]', $message, $orderTotal, $attempted));
    }
}
