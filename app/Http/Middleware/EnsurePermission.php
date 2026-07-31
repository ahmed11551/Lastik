<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if ($user === null) {
            abort(Response::HTTP_FORBIDDEN, 'Forbidden');
        }

        $permissions = $user->role?->permissions ?? [];

        if (! in_array($permission, $permissions, true) && ! in_array('*', $permissions, true)) {
            abort(Response::HTTP_FORBIDDEN, 'Forbidden');
        }

        return $next($request);
    }
}
