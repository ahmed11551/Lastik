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
 * Тестовый / dev драйвер: симулирует успешную фискализацию без реального ОФД.
 * Генерирует детерминированные тестовые ФД/ФН/ФП на основе idempotency_key,
 * чтобы повторные вызовы с тем же ключом давали идентичный результат (идемпотентность).
 */
final class NullFiscalDriver implements FiscalDriverInterface
{
    public function fiscalize(FiscalReceipt $receipt): FiscalResultDto
    {
        $seed = crc32((string) ($receipt->idempotency_key ?? $receipt->id));

        $fd = (string) (1000000 + ($seed % 8999999));
        $fn = (string) (9000000000000000 + ($seed % 999999999));
        $fp = str_pad((string) ($seed % 1000000000000000), 16, '0', STR_PAD_LEFT);
        $qr = 'https://null-ofd.local/check?fn=' . $fn . '&fd=' . $fd . '&fp=' . $fp;

        return FiscalResultDto::success($fd, $fn, $fp, $qr, 'null-' . $fd);
    }

    public function checkStatus(string $externalId): FiscalResultDto
    {
        return new FiscalResultDto(true, externalId: $externalId);
    }

    public function cancel(FiscalReceipt $receipt): bool
    {
        return true;
    }
}
