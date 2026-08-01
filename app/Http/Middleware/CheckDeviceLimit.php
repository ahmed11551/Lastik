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

use Autometria\Enums\DeviceType;
use Autometria\Models\Device;
use Autometria\Services\DeviceService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class CheckDeviceLimit
{
    private const MOBILE_LIMIT_MESSAGE = 'Превышен лимит активных смартфонов (не более 2 устройств)';

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

        // device_type is derived SERVER-SIDE from User-Agent, never from client input.
        $registeringMobile = DeviceType::detectFromUserAgent((string) $request->userAgent())
            ->value === DeviceType::MOBILE->value;

        if (! $registeringMobile) {
            return $next($request);
        }

        $limit = (int) ($user->devices_limit ?? 2);

        if ($this->devices->activeMobileCount($user, $limit) >= $limit) {
            abort(Response::HTTP_TOO_MANY_REQUESTS, self::MOBILE_LIMIT_MESSAGE);
        }

        return $next($request);
    }
}
