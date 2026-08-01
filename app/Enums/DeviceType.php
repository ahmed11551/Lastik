<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @package    Autometria\Enums
 * @copyright  (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author     Себиев Ахмед Сулейманович (Chief Software Architect / Lead Developer)
 * @license    Proprietary & Confidential.
 */

declare(strict_types=1);

namespace Autometria\Enums;

enum DeviceType: string
{
    case MOBILE = 'mobile';
    case DESKTOP = 'desktop';

    /**
     * Server-side detection strictly from User-Agent string.
     * Never trust client-supplied device_type.
     */
    public static function detectFromUserAgent(string $userAgent): self
    {
        $ua = strtolower($userAgent);

        // Tablet keywords first (treated as desktop for limit purposes, but flagged).
        if (preg_match('/(tablet|ipad|android(?!.*mobile)|kindle|playbook|silk)/', $ua)) {
            return self::DESKTOP;
        }

        // Mobile keywords.
        if (preg_match('/(android|iphone|ipod|blackberry|bb10|mini|windows\sphone|opera\smini|mobile|miui|huawei|honor|redmi)/', $ua)) {
            return self::MOBILE;
        }

        return self::DESKTOP;
    }
}
