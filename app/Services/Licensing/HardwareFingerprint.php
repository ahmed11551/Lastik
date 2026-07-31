<?php

/**
 * LASTIK B2B SaaS Engine Core
 *
 * @package    Lastik\Core
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
<?php

declare(strict_types=1);

namespace App\Services\Licensing;

use RuntimeException;

final class HardwareFingerprint
{
    public static function generate(): string
    {
        $cpuInfo = self::getCpuSerialNumber();
        $boardUuid = self::getMotherboardUuid();
        $machineId = self::getSystemMachineId();

        if (empty($cpuInfo) && empty($boardUuid)) {
            throw new RuntimeException('Hardware telemetry error: Unable to bind license.');
        }

        return hash('sha256', 'SEBIEV_AHMED_LASTIK_' . $cpuInfo . '_' . $boardUuid . '_' . $machineId);
    }

    private static function getCpuSerialNumber(): string
    {
        if (PHP_OS_FAMILY === 'Linux') {
            $cpu = @file_get_contents('/proc/cpuinfo');
            if ($cpu && preg_match('/Serial\s*:\s*([a-f0-9]+)/i', $cpu, $matches)) {
                return $matches[1];
            }
        }

        if (PHP_OS_FAMILY === 'Darwin') {
            $ioreg = @shell_exec('ioreg -rd1 -c IOPlatformExpertDevice | awk -F"\"" /IOPlatformUUID/{print $4}');
            if ($ioreg) {
                return hash('crc32', $ioreg);
            }
        }

        return 'cpu_fallback';
    }

    private static function getMotherboardUuid(): string
    {
        $paths = [
            '/sys/class/dmi/id/product_uuid',
            '/sys/class/dmi/id/board_serial',
        ];

        foreach ($paths as $path) {
            if (file_exists($path)) {
                $value = trim((string) @file_get_contents($path));
                if ($value !== '') {
                    return $value;
                }
            }
        }

        if (PHP_OS_FAMILY === 'Darwin') {
            $ioreg = @shell_exec('ioreg -rd1 -c IOPlatformExpertDevice | awk -F"\"" /board-id/{print $4}');
            if ($ioreg) {
                return hash('crc32', $ioreg);
            }
        }

        return 'mb_fallback';
    }

    private static function getSystemMachineId(): string
    {
        $paths = [
            '/etc/machine-id',
            '/var/lib/dbus/machine-id',
        ];

        foreach ($paths as $path) {
            if (file_exists($path)) {
                $value = trim((string) @file_get_contents($path));
                if ($value !== '') {
                    return $value;
                }
            }
        }

        if (PHP_OS_FAMILY === 'Darwin') {
            $id = @shell_exec('ioreg -rd1 -c IOPlatformExpertDevice | awk -F"\"" /IOPlatformUUID/{print $4}');
            if ($id) {
                return hash('sha256', $id);
            }
        }

        return 'machine_fallback';
    }
}
