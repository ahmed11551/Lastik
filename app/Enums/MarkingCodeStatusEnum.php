<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Enums;

enum MarkingCodeStatusEnum: string
{
    case EMITTED = 'EMITTED';
    case APPLIED = 'APPLIED';
    case SOLD = 'SOLD';
    case WRITTEN_OFF = 'WRITTEN_OFF';
}
