<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

use Autometria\Providers\AppServiceProvider;
use Autometria\Providers\EventServiceProvider;
use Autometria\Providers\HorizonServiceProvider;
use Autometria\Providers\RouteServiceProvider;

return [
    AppServiceProvider::class,
    EventServiceProvider::class,
    HorizonServiceProvider::class,
    RouteServiceProvider::class,
];
