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
        $userTenantId = $request->user()?->tenant_id !== null
            ? (int) $request->user()->tenant_id
            : null;

        if ($userTenantId === null) {
            throw new TenantAccessDeniedException('Tenant context is missing.');
        }

        $headerTenantId = $request->header('X-Tenant-ID');
        if ($headerTenantId !== null && $headerTenantId !== '' && (int) $headerTenantId !== $userTenantId) {
            throw new TenantAccessDeniedException('X-Tenant-ID does not match authenticated tenant.');
        }

        set_current_tenant_id($userTenantId);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('SELECT set_config(?, ?, true)', [
                'app.current_tenant_id',
                (string) $userTenantId,
            ]);
        }

        return $next($request);
    }
}
