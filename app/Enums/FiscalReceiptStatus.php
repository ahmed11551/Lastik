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
 * Статус фискального чека (жизненный цикл по 54-ФЗ).
 */
enum FiscalReceiptStatus: string
{
    case PENDING = 'pending';       // Создан, ожидает фискализации
    case FISCALIZED = 'fiscalized'; // Успешно пробит, получил ФД/ФН/ФП
    case FAILED = 'failed';         // Ошибка ОФД/драйвера (будет ретрай)
    case REFUNDED = 'refunded';     // Возвращён (sell_refund / buy_refund завершён)
}
