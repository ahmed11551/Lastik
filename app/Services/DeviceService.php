<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @package    Autometria\Services
 * @copyright  (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author     Себиев Ахмед Сулейманович (Chief Software Architect / Lead Developer)
 * @license    Proprietary & Confidential.
 */

declare(strict_types=1);

namespace Autometria\Services;

use Autometria\Enums\DeviceType;
use Autometria\Models\Device;
use Autometria\Models\User;
use Illuminate\Support\Facades\Request;

final class DeviceService
{
    /**
     * Register or refresh a device for the user after successful auth.
     * device_type is ALWAYS derived server-side from User-Agent — never from client input.
     *
     * @param  array<string, mixed>  $meta
     */
    public function register(
        User $user,
        string $fingerprint,
        string $deviceName,
        string $userAgent,
        ?string $ipAddress = null,
        array $meta = [],
    ): Device {
        $type = DeviceType::detectFromUserAgent($userAgent)->value;

        $device = Device::query()->withoutGlobalScopes()
            ->where('tenant_id', $user->tenant_id)
            ->where('user_id', $user->id)
            ->where('fingerprint', $fingerprint)
            ->first();

        if ($device === null) {
            $device = Device::query()->withoutGlobalScopes()->forceCreate([
                'tenant_id' => $user->tenant_id,
                'user_id' => $user->id,
                'device_name' => $deviceName,
                'device_type' => $type,
                'fingerprint' => $fingerprint,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'is_active' => true,
                'last_active_at' => now(),
                'is_current' => true,
            ]);
        } else {
            $device->update([
                'device_name' => $deviceName,
                'device_type' => $type,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'is_active' => true,
                'last_active_at' => now(),
                'is_current' => true,
            ]);
        }

        // Mark other devices of this user as not-current.
        Device::query()->withoutGlobalScopes()
            ->where('tenant_id', $user->tenant_id)
            ->where('user_id', $user->id)
            ->whereKeyNot($device->id)
            ->update(['is_current' => false]);

        return $device;
    }

    /**
     * Count active MOBILE devices for the user (used by device-limit guard).
     */
    public function activeMobileCount(User $user, int $limit = 2): int
    {
        $count = Device::query()->withoutGlobalScopes()
            ->where('tenant_id', $user->tenant_id)
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->where('device_type', DeviceType::MOBILE->value)
            ->count();

        return $count;
    }
}
