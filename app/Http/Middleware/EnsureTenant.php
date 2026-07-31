<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenantIdHeader = $request->header('X-Tenant-ID');
        $slug = $request->header('X-Tenant-Slug')
            ?? $request->input('tenant_slug')
            ?? env('DEFAULT_TENANT_SLUG', 'acceptance');

        $tenant = null;

        if ($request->user()?->tenant_id) {
            $tenant = Tenant::query()->find($request->user()->tenant_id);
        }

        if ($tenant === null && is_numeric($tenantIdHeader)) {
            $tenant = Tenant::query()->find((int) $tenantIdHeader);
        }

        if ($tenant === null && is_string($slug) && $slug !== '') {
            $tenant = Tenant::query()->where('slug', $slug)->first();
        }

        if ($tenant === null) {
            abort(406, 'Tenant not resolved');
        }

        app()->instance('current_tenant_id', (int) $tenant->id);
        $request->attributes->set('tenant', $tenant);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('SELECT set_config(?, ?, true)', [
                'app.current_tenant_id',
                (string) $tenant->id,
            ]);
        }

        return $next($request);
    }
}
