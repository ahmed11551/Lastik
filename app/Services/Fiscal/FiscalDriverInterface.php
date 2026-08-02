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
 *  - sell()         — пробить чек продажи/возврата.
 *  - checkStatus()  — сверка статуса по driver_request_id (для NEEDS_RECONCILE).
 *  - refund()       — аннулировать/возврат.
 *
 * Драйвер НЕ должен делать HTTP внутри транзакции БД (см. FiscalizeReceiptJob).
 */
interface FiscalDriverInterface
{
    public function sell(FiscalReceipt $receipt): FiscalResultDto;

    public function checkStatus(string $driverRequestId): FiscalResultDto;

    public function refund(FiscalReceipt $receipt): bool;
}
