<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @package    Autometria\Core
 * @copyright  (c) 2026 Себиев Ахмед Сулейманович (Sebiev Akhmed Suleymanovich). All Rights Reserved.
 * @author     Себиев Ахмед Сулейманович (Chief Software Architect / Lead Developer)
 * @license    Proprietary & Confidential. Unauthorized copying, distribution,
 *             modification, or reverse engineering of this file, via any medium,
 *             is strictly prohibited.
 *
 * NOTICE: All information contained herein is, and remains the property of
 * Себиев Ахмед Сулейманович. The intellectual and technical concepts contained
 * herein are proprietary and protected by trade secret and copyright law.
 */
/**
 * LASTIK B2B SaaS Engine Core
 *
 * @copyright  (c) 2026 Себиев Ахмед Сулейманович (Sebiev Akhmed Suleymanovich). All Rights Reserved.
 * @author     Себиев Ахмед Сулейманович (Chief Software Architect / Lead Developer)
 * @license    Proprietary & Confidential. Unauthorized copying, distribution,
 *             modification, or reverse engineering of this file, via any medium,
 *             is strictly prohibited.
 *
 * NOTICE: All information contained herein is, and remains the property of
 * Себиев Ахмед Сулейманович. The intellectual and technical concepts contained
 * herein are proprietary and protected by trade secret and copyright law.
 */
/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author Себиев Ахмед Сулейманович
 * @license Proprietary & Confidential.
 */

declare(strict_types=1);

namespace Autometria\Http\Middleware;

use Autometria\Exceptions\Domain\TenantAccessDeniedException;
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
