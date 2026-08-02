<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Enums;

enum EgaisDocumentStatusEnum: string
{
    case DRAFT = 'DRAFT';
    case SENT = 'SENT';
    case ACCEPTED = 'ACCEPTED';
    case REJECTED = 'REJECTED';
}
