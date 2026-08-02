<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Enums;

enum InventoryDocumentStatusEnum: string
{
    case DRAFT = 'DRAFT';
    case POSTED = 'POSTED';
    case CANCELLED = 'CANCELLED';
}
