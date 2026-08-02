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

use Autometria\Exceptions\Domain\FiscalNetworkTimeoutException;
use Autometria\Models\FiscalReceipt;

/**
 * Тестовый / dev драйвер: симулирует успешную фискализацию без реального ОФД.
 *
 *  - sell(): генерирует детерминированные ФД/ФН/ФП по driver_request_id
 *    (идемпотентность — тот же id даёт тот же результат).
 *  - если в payload_snapshot['simulate_timeout'] = true — бросает
 *    FiscalNetworkTimeoutException (проверка перехода в NEEDS_RECONCILE).
 *  - checkStatus(): если флаг not_found — возвращает notFound, иначе success.
 */
final class NullFiscalDriver implements FiscalDriverInterface
{
    public function sell(FiscalReceipt $receipt): FiscalResultDto
    {
        $snapshot = $receipt->payload_snapshot ?? [];
        if (! empty($snapshot['simulate_timeout'])) {
            throw new FiscalNetworkTimeoutException('Simulated OFD network timeout');
        }

        $seed = crc32((string) $receipt->driver_request_id);

        $fd = (string) (1000000 + ($seed % 8999999));
        $fn = (string) (9000000000000000 + ($seed % 999999999));
        $fp = str_pad((string) ($seed % 1000000000000000), 16, '0', STR_PAD_LEFT);
        $qr = 'https://null-ofd.local/check?fn=' . $fn . '&fd=' . $fd . '&fp=' . $fp;

        return FiscalResultDto::success($fd, $fn, $fp, $qr, (string) $receipt->driver_request_id);
    }

    public function checkStatus(string $driverRequestId): FiscalResultDto
    {
        if (str_ends_with($driverRequestId, '-notfound')) {
            return FiscalResultDto::notFound($driverRequestId);
        }

        $seed = crc32($driverRequestId);
        $fd = (string) (1000000 + ($seed % 8999999));
        $fn = (string) (9000000000000000 + ($seed % 999999999));
        $fp = str_pad((string) ($seed % 1000000000000000), 16, '0', STR_PAD_LEFT);
        $qr = 'https://null-ofd.local/check?fn=' . $fn . '&fd=' . $fd . '&fp=' . $fp;

        return FiscalResultDto::success($fd, $fn, $fp, $qr, $driverRequestId);
    }

    public function refund(FiscalReceipt $receipt): bool
    {
        return true;
    }
}
