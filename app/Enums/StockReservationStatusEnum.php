<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Enums;

enum StockReservationStatusEnum: string
{
    case ACTIVE = 'ACTIVE';
    case FULFILLED = 'FULFILLED';
    case RELEASED = 'RELEASED';
}
