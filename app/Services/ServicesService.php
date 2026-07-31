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

namespace Autometria\Services;

use Autometria\Models\ProductService;

class ServicesService
{
    public function calculateServicePrice(ProductService $service, ?string $wheelRadius): float
    {
        $base = (float) ($service->base_price ?? 0.0);

        $modifier = $this->resolveModifier($service->radius_modifier, $wheelRadius);

        return round($base + $modifier, 2);
    }

    /**
     * @param  array<string, mixed>|null  $modifiers
     */
    private function resolveModifier(?array $modifiers, ?string $radius): float
    {
        if ($modifiers === null || $radius === null || $radius === '') {
            return 0.0;
        }

        $normalized = strtolower((string) $radius);
        $normalized = preg_replace('/[^a-z0-9]/', '', $normalized) ?? $normalized;

        foreach ($modifiers as $key => $value) {
            if (preg_replace('/[^a-z0-9]/', '', strtolower((string) $key)) === $normalized) {
                return (float) $value;
            }
        }

        return 0.0;
    }
}
