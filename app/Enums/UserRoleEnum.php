<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @package    Autometria\Enums
 * @copyright  (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author     Себиев Ахмед Сулейманович
 * @license    Proprietary & Confidential.
 */

declare(strict_types=1);

namespace Autometria\Enums;

enum UserRoleEnum: string
{
    case ADMIN = 'admin';
    case OWNER = 'owner';
    case MASTER = 'master';
    case SELLER = 'seller';
    case WAREHOUSE_MANAGER = 'warehouse_manager';
    case CASHIER = 'cashier';

    public function label(): string
    {
        return match ($this) {
            self::ADMIN, self::OWNER => 'Администратор',
            self::MASTER, self::SELLER => 'Мастер-приемщик',
            self::WAREHOUSE_MANAGER => 'Кладовщик',
            self::CASHIER => 'Бухгалтер',
        };
    }

    public static function labelFor(?string $slug): ?string
    {
        if ($slug === null || $slug === '') {
            return null;
        }

        return self::tryFrom($slug)?->label();
    }

    /**
     * @return list<string>
     */
    public static function displayLabels(): array
    {
        return array_values(array_unique(array_map(
            static fn (self $role): string => $role->label(),
            self::cases(),
        )));
    }
}
