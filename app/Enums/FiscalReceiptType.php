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
 * Тип фискального документа (тег 1054 по ФФД 54-ФЗ).
 */
enum FiscalReceiptType: string
{
    case SELL = 'sell';               // Приход (продажа)
    case SELL_REFUND = 'sell_refund'; // Возврат прихода
    case BUY = 'buy';                 // Расход (закупка)
    case BUY_REFUND = 'buy_refund';   // Возврат расхода
}
