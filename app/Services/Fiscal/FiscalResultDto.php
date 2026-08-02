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

/**
 * Результат фискализации от драйвера ОФД/ККТ.
 */
final class FiscalResultDto
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $fiscalDocumentNumber = null,  // ФД
        public readonly ?string $fiscalStorageNumber = null,   // ФН
        public readonly ?string $fiscalSign = null,            // ФП / ФПД
        public readonly ?string $qrCodeUrl = null,
        public readonly ?string $externalId = null,            // driver_request_id (для checkStatus)
        public readonly ?string $errorMessage = null,
        public readonly bool $needsReconcile = false,          // сетевой таймаут / 5xx / unknown
        public readonly bool $notFound = false,                // ККТ: документ с этим id не существует
    ) {}

    public static function success(
        string $fd,
        string $fn,
        string $fp,
        string $qr,
        ?string $externalId = null,
    ): self {
        return new self(true, $fd, $fn, $fp, $qr, $externalId);
    }

    public static function failure(string $message, bool $needsReconcile = false): self
    {
        return new self(false, null, null, null, null, null, $message, $needsReconcile);
    }

    public static function notFound(string $externalId): self
    {
        return new self(false, externalId: $externalId, notFound: true);
    }
}
