<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Jobs\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * RLS tenant context for queue workers (outside HTTP middleware).
 */
trait SetsTenantContext
{
    protected function bindTenantContext(int $tenantId): void
    {
        set_current_tenant_id($tenantId);
    }

    protected function clearTenantContext(): void
    {
        app()->instance('current_tenant_id', null);

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Session-level clear so the next job on a pooled connection cannot inherit tenant.
        DB::statement("SELECT set_config('app.current_tenant_id', '', false)");
    }
}
