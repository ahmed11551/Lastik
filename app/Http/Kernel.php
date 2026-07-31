<?php

namespace App\Http;

use App\Http\Middleware\Authenticate;
use App\Http\Middleware\EncryptCookies;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RedirectIfAuthenticated;
use App\Http\Middleware\TrimStrings;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\ValidatePostSize;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ValidateSignature;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class Kernel extends HttpKernel
{
    protected $middleware = [
        ValidatePostSize::class,
        PreventRequestsDuringMaintenance::class,
        TrimStrings::class,
        ConvertEmptyStringsToNull::class,
    ];

    protected $middlewareGroups = [
        'web' => [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            ValidateCsrfToken::class,
            SubstituteBindings::class,
            HandleInertiaRequests::class,
        ],

        'api' => [
            'throttle:api',
            SubstituteBindings::class,
        ],
    ];

    protected $routeMiddleware = [
        'auth' => Authenticate::class,
        'guest' => RedirectIfAuthenticated::class,
        'signed' => ValidateSignature::class,
        'throttle' => ThrottleRequests::class,
        'bindings' => SubstituteBindings::class,
    ];
}
