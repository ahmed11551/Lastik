<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Enums;

enum EgaisDocTypeEnum: string
{
    case ACT_WRITE_OFF = 'ACT_WRITE_OFF';
    case WAYBILL = 'WAYBILL';
    case UNSEAL = 'UNSEAL';
}
