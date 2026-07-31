<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class RateLimitAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->ip();

        if (RateLimiter::tooManyAttempts($key, 10)) {
            abort(Response::HTTP_TOO_MANY_REQUESTS, 'Too many login attempts');
        }

        RateLimiter::hit($key, 60);

        $response = $next($request);

        if ($response->isSuccessful()) {
            RateLimiter::clear($key);
        }

        return $response;
    }
}
