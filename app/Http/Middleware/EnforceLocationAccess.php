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

use Autometria\Models\Location;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Изоляция по точке (п. 8): пользователь без locations.all
 * работает только в пределах своего location_id.
 */
class EnforceLocationAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        $permissions = $user->role?->permissions ?? [];
        $canAllLocations = in_array('locations.all', $permissions, true)
            || in_array('admin.dashboard', $permissions, true);

        $headerLocation = $request->header('X-Location-ID');
        $headerLocationId = is_numeric($headerLocation) ? (int) $headerLocation : null;

        if ($canAllLocations) {
            if ($headerLocationId !== null) {
                $belongs = Location::query()
                    ->whereKey($headerLocationId)
                    ->where('tenant_id', (int) $user->tenant_id)
                    ->exists();
                abort_unless($belongs, Response::HTTP_FORBIDDEN, 'Location does not belong to current tenant');
            }

            // Admin may narrow scope via X-Location-ID; otherwise see all points.
            app()->instance('current_location_id', $headerLocationId);
            $request->attributes->set('location_scope', $headerLocationId ?? 'all');

            return $next($request);
        }

        $locationId = $user->location_id ? (int) $user->location_id : null;

        if ($headerLocationId !== null && $locationId !== null && $headerLocationId !== $locationId) {
            abort(Response::HTTP_FORBIDDEN, 'Location access denied');
        }

        if ($locationId !== null) {
            $belongs = Location::query()
                ->whereKey($locationId)
                ->where('tenant_id', (int) $user->tenant_id)
                ->exists();
            abort_unless($belongs, Response::HTTP_FORBIDDEN, 'Location does not belong to current tenant');
        }

        app()->instance('current_location_id', $locationId);
        $request->attributes->set('location_scope', $locationId);

        // Прямой доступ к чужой точке по ID в payload
        foreach (['location_id'] as $key) {
            if ($request->filled($key) && $locationId !== null && (int) $request->input($key) !== $locationId) {
                abort(Response::HTTP_FORBIDDEN, 'Location access denied');
            }
        }

        return $next($request);
    }
}
