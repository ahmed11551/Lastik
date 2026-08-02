<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @package    Autometria\Http\Middleware
 * @copyright  (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author     Себиев Ахмед Сулейманович (Chief Software Architect / Lead Developer)
 * @license    Proprietary & Confidential.
 */

declare(strict_types=1);

namespace Autometria\Http\Middleware;

use Autometria\Services\DeviceService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * P0 hard device limit: a user may have at most TOTAL_DEVICE_CAP active devices
 * across ALL types (mobile + desktop). No desktop exception (Грок P0 review).
 *
 * The actual enforcement (count + create) happens under pessimistic lock inside
 * DeviceService::register(); this middleware is a fast pre-check that short-circuits
 * the request before auth proceeds, returning 429 with a clear message.
 */
class CheckDeviceLimit
{
    private const LIMIT_MESSAGE = 'Превышен лимит активных устройств (не более 3 устройств, включая ПК)';

    public function __construct(
        private readonly DeviceService $devices = new DeviceService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user === null) {
            return $next($request);
        }

        $routeName = $request->route()?->getName() ?? '';
        if (! str_starts_with($routeName, 'auth.devices.register')) {
            return $next($request);
        }

        $cap = DeviceService::TOTAL_DEVICE_CAP;

        // Fast pre-check (the authoritative enforcement is in DeviceService under lockForUpdate).
        if ($this->devices->activeTotalCount($user) >= $cap) {
            abort(Response::HTTP_TOO_MANY_REQUESTS, self::LIMIT_MESSAGE);
        }

        return $next($request);
    }
}
