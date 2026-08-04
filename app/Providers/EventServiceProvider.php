<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Providers;

use Autometria\Events\OrderStatusChanged;
use Autometria\Listeners\InvalidateTvBoardCache;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, list<class-string>>
     */
    protected $listen = [
        OrderStatusChanged::class => [
            InvalidateTvBoardCache::class,
        ],
    ];

    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
