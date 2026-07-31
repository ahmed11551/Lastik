<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class CheckDeviceLimit
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user === null) {
            return $next($request);
        }

        // Load devices relation if not already loaded
        if (! $user->relationLoaded('devices')) {
            $user->load('devices');
        }

        $activeDevices = $user->devices->where('is_active', true)->count();
        $limit = (int) ($user->devices_limit ?? 2);

        // Deny only if the current request is about to register a NEW device beyond limit.
        // We allow login itself to pass; subsequent requests with already-known devices
        // are checked in the controller/service by device/refresh-token logic.
        $routeName = $request->route()?->getName() ?? '';

        if (str_starts_with($routeName, 'auth.devices.register') && $activeDevices >= $limit) {
            abort(Response::HTTP_TOO_MANY_REQUESTS, 'Device limit exceeded');
        }

        return $next($request);
    }
}
