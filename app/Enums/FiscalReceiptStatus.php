<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @package    Autometria\Enums
 * @copyright  (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author     Себиев Ахмед Сулейманович (Chief Software Architect / Lead Developer)
 * @license    Proprietary & Confidential.
 */

declare(strict_types=1);

namespace Autometria\Enums;

/**
 * Статус фискального чека (жизненный цикл по 54-ФЗ, версия аудита Grok).
 *
 *  PENDING          — создан, ожидает фискализации
 *  IN_PROGRESS      — захвачен воркером (claim), идёт HTTP к ККТ
 *  FISCALIZED       — успешно пробит, есть ФД/ФН/ФП
 *  FAILED_RETRYABLE — фатальная ошибка валидации 54-ФЗ (retry по backoff)
 *  FAILED_FINAL     — необратимая ошибка (не retry)
 *  NEEDS_RECONCILE  — сетевой таймаут/5xx/unknown — требует сверки через checkStatus
 */
enum FiscalReceiptStatus: string
{
    case PENDING = 'pending';
    case IN_PROGRESS = 'in_progress';
    case FISCALIZED = 'fiscalized';
    case FAILED_RETRYABLE = 'failed_retryable';
    case FAILED_FINAL = 'failed_final';
    case NEEDS_RECONCILE = 'needs_reconcile';
}
