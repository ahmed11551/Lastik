<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Enums;

enum MarkingTypeEnum: string
{
    case TOBACCO = 'TOBACCO';
    case PERFUME = 'PERFUME';
    case SHOES = 'SHOES';
    case MILK = 'MILK';
    case WATER = 'WATER';
    case ALCOHOL_BEER = 'ALCOHOL_BEER';
    case ALCOHOL_STRONG = 'ALCOHOL_STRONG';
}
