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
use Illuminate\Support\Facades\DB;

final class DeviceService
{
    /**
     * Hard cap on the TOTAL number of active devices per user (mobile + desktop).
     * No exception for desktop UA — Грок P0 review requires a uniform cap.
     */
    public const TOTAL_DEVICE_CAP = 3;

    /**
     * Register or refresh a device for the user after successful auth.
     * device_type is ALWAYS derived server-side from User-Agent — never from client input.
     *
     * The count and creation are performed under a pessimistic lock
     * (lockForUpdate on the user's device rows) so concurrent registrations
     * cannot overshoot the cap.
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

        return DB::transaction(function () use ($user, $fingerprint, $deviceName, $type, $userAgent, $ipAddress, $meta): Device {
            // Pessimistic lock on the user's device rows BEFORE counting/creating.
            Device::query()->withoutGlobalScopes()
                ->where('tenant_id', $user->tenant_id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->get();

            $device = Device::query()->withoutGlobalScopes()
                ->where('tenant_id', $user->tenant_id)
                ->where('user_id', $user->id)
                ->where('fingerprint', $fingerprint)
                ->first();

            if ($device === null) {
                // Enforce the TOTAL cap (all device types) under the lock.
                $activeCount = Device::query()->withoutGlobalScopes()
                    ->where('tenant_id', $user->tenant_id)
                    ->where('user_id', $user->id)
                    ->where('is_active', true)
                    ->count();

                if ($activeCount >= self::TOTAL_DEVICE_CAP) {
                    throw new \RuntimeException(
                        'Device limit reached: maximum ' . self::TOTAL_DEVICE_CAP . ' active devices per user'
                    );
                }

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
        });
    }

    /**
     * Count ALL active devices for the user (mobile + desktop) — used by the hard cap.
     */
    public function activeTotalCount(User $user): int
    {
        return Device::query()->withoutGlobalScopes()
            ->where('tenant_id', $user->tenant_id)
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->count();
    }
}
