<?php

/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author Себиев Ахмед Сулейманович
 * @license Proprietary & Confidential.
 */

declare(strict_types=1);

use Autometria\Http\Middleware\CheckDeviceLimit;
use Autometria\Http\Middleware\EnforceAutometriaLicense;
use Autometria\Http\Middleware\EnforceLocationAccess;
use Autometria\Http\Middleware\EnsurePermission;
use Autometria\Http\Middleware\EnsureTenant;
use Autometria\Http\Middleware\SupportAccess;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up'
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(EnforceAutometriaLicense::class);
        $middleware->alias([
            'ensure.tenant' => EnsureTenant::class,
            'ensure.permission' => EnsurePermission::class,
            'ensure.location' => EnforceLocationAccess::class,
            'check.device' => CheckDeviceLimit::class,
            'support.access' => SupportAccess::class,
            'auth.license' => EnforceAutometriaLicense::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {})
    ->create();
