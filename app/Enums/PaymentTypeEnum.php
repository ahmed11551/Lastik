<?php

declare(strict_types=1);

namespace App\Enums;

enum PaymentTypeEnum: string
{
    case CASH = 'cash';
    case CARD = 'card';
    case SBP = 'sbp';
    case TERMINAL = 'terminal';
}
