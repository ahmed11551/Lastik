<?php

declare(strict_types=1);

namespace App\Enums;

enum OrderStatusEnum: string
{
    case DRAFT = 'draft';
    case PENDING = 'pending';
    case IN_PROGRESS = 'in_progress';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';
}
