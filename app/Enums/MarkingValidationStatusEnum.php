<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Enums;

enum MarkingValidationStatusEnum: string
{
    case VALID = 'VALID';
    case INVALID = 'INVALID';
    case EXPIRED = 'EXPIRED';
    case SOLD = 'SOLD';
}
