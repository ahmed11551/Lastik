<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

if (! function_exists('set_current_tenant_id')) {
    /**
     * Биндит tenant в контейнер и (для pgsql) в session GUC для RLS.
     */
    function set_current_tenant_id(?int $tenantId): void
    {
        app()->instance('current_tenant_id', $tenantId);

        if ($tenantId === null || DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('SELECT set_config(?, ?, true)', [
            'app.current_tenant_id',
            (string) $tenantId,
        ]);
    }
}

if (! function_exists('tenant_id')) {
    /**
     * Текущий tenant_id из аутентифицированного пользователя или контейнера.
     */
    function tenant_id(): ?int
    {
        if (app()->bound('current_tenant_id')) {
            $bound = app('current_tenant_id');

            return $bound !== null ? (int) $bound : null;
        }

        $user = Auth::user();

        if ($user && isset($user->tenant_id)) {
            return (int) $user->tenant_id;
        }

        return null;
    }
}

if (! function_exists('location_id')) {
    function location_id(): ?int
    {
        if (app()->bound('current_location_id')) {
            $bound = app('current_location_id');

            return $bound !== null ? (int) $bound : null;
        }

        $user = Auth::user();

        return $user && isset($user->location_id) ? (int) $user->location_id : null;
    }
}
