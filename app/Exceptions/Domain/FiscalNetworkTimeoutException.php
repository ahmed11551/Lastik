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
 * Сетевой таймаут / разрыв соединения с ОФД или ККТ.
 * По ТЗ аудита: при таком сбое чек НЕ уходит в retryable-sell, а переходит
 * в NEEDS_RECONCILE (требуется сверка через checkStatus).
 */
final class FiscalNetworkTimeoutException extends \RuntimeException
{
}
