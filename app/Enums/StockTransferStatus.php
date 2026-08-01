<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @package    Autometria\Enums
 * @copyright  (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author     Себиев Ахмед Сулейманович (Chief Software Architect / Lead Developer)
 * @license    Proprietary & Confidential.
 */

declare(strict_types=1);

namespace Autometria\Enums;

enum StockTransferStatus: string
{
    case DRAFT = 'draft';
    case IN_TRANSIT = 'in_transit';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';
}
