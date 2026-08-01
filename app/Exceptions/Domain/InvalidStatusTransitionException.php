<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @package    Autometria\Exceptions\Domain
 * @copyright  (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author     Себиев Ахмед Сулейманович
 * @license    Proprietary & Confidential. Unauthorized copying, distribution,
 *             modification, or reverse engineering of this file, via any medium,
 *             is strictly prohibited.
 */

declare(strict_types=1);

namespace Autometria\Exceptions\Domain;

use DomainException;
use RuntimeException;

/**
 * Thrown when a bulk or single status transition is invalid.
 *
 * Example: attempting to move a completed/cancelled order back to pending.
 */
class InvalidStatusTransitionException extends RuntimeException
{
    public function __construct(
        string $currentStatus,
        string $targetStatus,
        string $context = '',
        int $code = 422,
    ) {
        $message = sprintf(
            'Invalid status transition: "%s" → "%s"%s',
            $currentStatus,
            $targetStatus,
            $context !== '' ? " for {$context}" : '',
        );

        parent::__construct($message, $code);
    }
}
