<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @package    Autometria\Exceptions\Domain
 * @copyright  (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Exceptions\Domain;

/**
 * Нарушение бизнес-инвариантов чека перед отправкой в ККТ (тег 1079, сходимость копеек).
 */
final class FiscalizationValidationException extends \DomainException
{
}
