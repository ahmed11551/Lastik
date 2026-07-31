<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Support\AuditLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SupportAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->role !== 'platform_owner') {
            $tenantId = tenant_id();

            $tenant = Tenant::query()->find($tenantId);

            if ($tenant === null || ! (bool) $tenant->support_access_enabled) {
                abort(Response::HTTP_FORBIDDEN, 'Support access is not enabled for this tenant');
            }

            AuditLog::write(
                (int) $tenantId,
                $request->user()?->id,
                'support_access_view',
                'tenant',
                (int) $tenantId,
                [],
                [],
                [
                    'path' => $request->path(),
                    'method' => $request->method(),
                    'ip' => $request->ip(),
                    'user_agent' => (string) $request->header('user-agent'),
                ]
            );
        }

        return $next($request);
    }
}
