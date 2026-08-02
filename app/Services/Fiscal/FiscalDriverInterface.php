<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @package    Autometria\Services\Fiscal
 * @copyright  (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author     Себиев Ахмед Сулейманович (Chief Software Architect / Lead Developer)
 * @license    Proprietary & Confidential.
 */

declare(strict_types=1);

namespace Autometria\Services\Fiscal;

use Autometria\Models\FiscalReceipt;

/**
 * Контракт драйвера фискализации (54-ФЗ).
 *
 * Реализации:
 *  - NullFiscalDriver  — тест/dev (симулирует успех без реального ОФД).
 *  - AtolOnlineDriver  — облачный ОФД (Атол Онлайн / ККМ-агент).
 */
interface FiscalDriverInterface
{
    /**
     * Пробить чек. Возвращает результат от ОФД/ККТ.
     */
    public function fiscalize(FiscalReceipt $receipt): FiscalResultDto;

    /**
     * Проверить статус ранее отправленного чека по внешнему id.
     */
    public function checkStatus(string $externalId): FiscalResultDto;

    /**
     * Аннулировать/отменить чек (возврат).
     */
    public function cancel(FiscalReceipt $receipt): bool;
}
