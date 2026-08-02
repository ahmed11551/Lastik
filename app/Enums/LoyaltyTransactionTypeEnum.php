<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Enums;

enum LoyaltyTransactionTypeEnum: string
{
    case EARN = 'EARN';
    case SPEND = 'SPEND';
    case EXPIRE = 'EXPIRE';
    case MANUAL_ADJUST = 'MANUAL_ADJUST';
}
