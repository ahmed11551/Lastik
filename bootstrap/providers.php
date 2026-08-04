<?php

/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author Себиев Ахмед Сулейманович
 * @license Proprietary & Confidential.
 */

use Autometria\Providers\AppServiceProvider;
use Autometria\Providers\EventServiceProvider;
use Autometria\Providers\RouteServiceProvider;

return [
    AppServiceProvider::class,
    EventServiceProvider::class,
    RouteServiceProvider::class,
];
