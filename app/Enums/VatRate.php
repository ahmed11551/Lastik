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
 * Ставка НДС (тег 1199 / 1102 по ФФД 54-ФЗ).
 */
enum VatRate: string
{
    case VAT_20 = '20';
    case VAT_10 = '10';
    case VAT_20_120 = '20/120'; // расчётная 20/120
    case VAT_10_110 = '10/110'; // расчётная 10/110
    case VAT_0 = '0';
    case NONE = 'none';         // без НДС

    public function toFfdTag(): string
    {
        return $this->value;
    }

    /**
     * Сумма НДС для позиции (в рублях), округлённая до копеек.
     */
    public function amountFor(float $price, float $quantity = 1.0): float
    {
        $base = $price * $quantity;

        return match ($this) {
            self::VAT_20 => round($base * 20 / 120, 2),
            self::VAT_10 => round($base * 10 / 110, 2),
            self::VAT_20_120 => round($base * 20 / 120, 2),
            self::VAT_10_110 => round($base * 10 / 110, 2),
            self::VAT_0, self::NONE => 0.0,
        };
    }
}
