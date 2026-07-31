<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Lastik\Models\Tenant;
use Lastik\Support\AuditLog;
use Symfony\Component\HttpFoundation\Response;

class SupportAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->role !== 'platform_owner') {
            $tenantId = tenant_id();

            $tenant = Tenant::withoutGlobalScope('tenant')->find($tenantId);

            if ($tenant === null || ! (bool) $tenant->supportAccessEnabled) {
                abort(Response::HTTP_FORBIDDEN, 'Support access is not enabled for this tenant');
            }

            AuditLog::write(
                $tenantId,
                $request->user()?->id,
                'support_access_view',
                'tenant',
                $tenantId,
                null,
                null,
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
