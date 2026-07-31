<?php

declare(strict_types=1);

namespace App\Enums;

enum ShiftStatusEnum: string
{
    case OPENED = 'opened';
    case CLOSED = 'closed';
}
