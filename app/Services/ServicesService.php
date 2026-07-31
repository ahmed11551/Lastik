<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ProductService;

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
