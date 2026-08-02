<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Services\Analytics;

use Illuminate\Support\Facades\Cache;

/**
 * Redis/array cache for heavy financial analytics with tenant-scoped invalidation.
 */
final class AnalyticsCacheService
{
    private const TTL_SECONDS = 300;

    public function __construct(
        private readonly AnalyticsReportService $reports,
    ) {}

    /**
     * Cached Net Revenue / Gross Profit summary.
     *
     * @return array<string, mixed>
     */
    public function getDashboardSummary(
        int $tenantId,
        ?string $dateFrom,
        ?string $dateTo,
        ?int $warehouseId,
    ): array {
        $key = $this->key('summary', $tenantId, $dateFrom, $dateTo, $warehouseId);

        return Cache::remember($key, self::TTL_SECONDS, fn () => $this->reports->getDashboardSummary(
            $tenantId,
            $dateFrom,
            $dateTo,
            $warehouseId,
        ));
    }

    /**
     * Cached full dashboard (includes summary + turnover + ABC/XYZ top).
     *
     * @return array<string, mixed>
     */
    public function getDashboard(
        int $tenantId,
        ?string $dateFrom,
        ?string $dateTo,
        ?int $warehouseId,
        int $topLimit = 10,
    ): array {
        $key = $this->key('dashboard', $tenantId, $dateFrom, $dateTo, $warehouseId, (string) $topLimit);

        return Cache::remember($key, self::TTL_SECONDS, fn () => $this->reports->getDashboard(
            $tenantId,
            $dateFrom,
            $dateTo,
            $warehouseId,
            $topLimit,
        ));
    }

    /**
     * Cached ABC/XYZ analysis.
     *
     * @return array<string, mixed>
     */
    public function getAbcXyzAnalysis(
        int $tenantId,
        ?string $dateFrom,
        ?string $dateTo,
        ?int $warehouseId,
    ): array {
        $key = $this->key('abcxyz', $tenantId, $dateFrom, $dateTo, $warehouseId);

        return Cache::remember($key, self::TTL_SECONDS, fn () => $this->reports->getAbcXyzAnalysis(
            $tenantId,
            $dateFrom,
            $dateTo,
            $warehouseId,
        ));
    }

    /**
     * Invalidate all analytics keys for a tenant (call on new receipt / stock doc).
     */
    public function invalidateTenant(int $tenantId): void
    {
        $versionKey = $this->versionKey($tenantId);
        Cache::forever($versionKey, (int) Cache::get($versionKey, 1) + 1);
    }

    private function key(string $metric, int $tenantId, ?string $from, ?string $to, ?int $warehouseId, string $extra = ''): string
    {
        $version = (int) Cache::get($this->versionKey($tenantId), 1);

        return implode(':', [
            'analytics',
            (string) $tenantId,
            'v'.$version,
            $metric,
            $from ?? '-',
            $to ?? '-',
            $warehouseId !== null ? (string) $warehouseId : '-',
            $extra,
        ]);
    }

    private function versionKey(int $tenantId): string
    {
        return 'analytics:tenant:'.$tenantId.':version';
    }
}
