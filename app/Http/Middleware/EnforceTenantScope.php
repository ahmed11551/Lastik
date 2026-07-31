<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\Domain\TenantAccessDeniedException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EnforceTenantScope
{
    public function handle(Request $request, Closure $next)
    {
        $tenantId = $request->user()?->tenant_id ?? $request->header('X-Tenant-ID');

        if (! $tenantId) {
            throw new TenantAccessDeniedException('Tenant context is missing.');
        }

        DB::statement('SET LOCAL app.current_tenant_id = ?', [(int) $tenantId]);

        return $next($request);
    }
}
