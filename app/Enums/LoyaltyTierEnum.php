<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Enums;

enum LoyaltyTierEnum: string
{
    case BRONZE = 'BRONZE';
    case SILVER = 'SILVER';
    case GOLD = 'GOLD';

    public function earnRate(): float
    {
        return match ($this) {
            self::BRONZE => 0.03,
            self::SILVER => 0.05,
            self::GOLD => 0.10,
        };
    }

    public static function fromTotalSpent(float $totalSpent): self
    {
        if ($totalSpent >= 150_000) {
            return self::GOLD;
        }
        if ($totalSpent >= 50_000) {
            return self::SILVER;
        }

        return self::BRONZE;
    }
}
