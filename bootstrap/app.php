<?php

/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author Себиев Ахмед Сулейманович
 * @license Proprietary & Confidential.
 */

declare(strict_types=1);

use Autometria\Exceptions\Domain\InsufficientStockException;
use Autometria\Exceptions\Domain\NoActiveShiftException;
use Autometria\Exceptions\Domain\ShiftAlreadyClosedException;
use Autometria\Exceptions\Domain\ShiftAlreadyOpenedException;
use Autometria\Exceptions\Domain\TenantAccessDeniedException;
use Autometria\Http\Middleware\CheckDeviceLimit;
use Autometria\Http\Middleware\EnforceAutometriaLicense;
use Autometria\Http\Middleware\EnforceLocationAccess;
use Autometria\Http\Middleware\EnsurePermission;
use Autometria\Http\Middleware\EnsureTenant;
use Autometria\Http\Middleware\SupportAccess;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

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
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            if ($e instanceof AuthenticationException) {
                return response()->json(['message' => 'Unauthenticated'], 401);
            }

            if ($e instanceof ValidationException) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'errors' => $e->errors(),
                ], 422);
            }

            if ($e instanceof TenantAccessDeniedException) {
                return response()->json(['message' => $e->getMessage(), 'code' => 'tenant_denied'], 403);
            }

            if (
                $e instanceof NoActiveShiftException
                || $e instanceof ShiftAlreadyClosedException
                || $e instanceof ShiftAlreadyOpenedException
                || $e instanceof InsufficientStockException
            ) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'code' => class_basename($e),
                ], 422);
            }

            if ($e instanceof HttpExceptionInterface) {
                return response()->json([
                    'message' => $e->getMessage() ?: 'HTTP Error',
                ], $e->getStatusCode());
            }

            return null;
        });
    })
    ->create();
