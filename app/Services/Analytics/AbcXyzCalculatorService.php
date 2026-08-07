<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Services\Analytics;

use Autometria\Models\ProductClassification;
use Autometria\Models\StockLotDeduction;
use Autometria\Services\Analytics\AnalyticsReportService;
use Illuminate\Support\Facades\DB;

/**
 * ABC/XYZ classification engine (v1.4.0 Smart Analytics).
 *
 * - ABC: cumulative revenue (gross profit) share per product.
 *       A = ≤80% of cumulative revenue, B = ≤95%, C = rest.
 * - XYZ: coefficient of variation of monthly demand.
 *       X = <10%, Y = 10–25%, Z = >25%.
 *
 * Results are persisted into product_classifications for fast reads.
 */
final class AbcXyzCalculatorService
{
    public function __construct(
        private readonly AnalyticsReportService $reports,
    ) {}

    /**
     * Run the full matrix for a tenant and persist it.
     *
     * @return array{upserted: int, a: int, b: int, c: int, x: int, y: int, z: int, period_days: int}
     */
    public function calculateForTenant(int $tenantId, int $periodDays = 90): array
    {
        $from = now()->subDays($periodDays)->startOfDay();
        $to = now();

        // --- ABC: revenue share per product (from existing COGS breakdown) ---
        $cogs = $this->reports->getCogsBreakdown($tenantId, $from->toDateString(), $to->toDateString(), null);
        $totalRevenue = (float) array_sum(array_column($cogs, 'gross_profit'));

        $abcMap = [];
        $revenueShare = [];
        $cum = 0.0;
        $sorted = $cogs;
        usort($sorted, fn ($a, $b) => $b['gross_profit'] <=> $a['gross_profit']);
        foreach ($sorted as $r) {
            $gp = (float) $r['gross_profit'];
            $cum += $gp;
            $share = $totalRevenue > 0 ? ($cum / $totalRevenue) * 100 : 0;
            $revenueShare[$r['product_id']] = $totalRevenue > 0 ? ($gp / $totalRevenue) * 100 : 0;
            $abcMap[$r['product_id']] = $share <= 80 ? 'A' : ($share <= 95 ? 'B' : 'C');
        }

        // --- XYZ: coefficient of variation of monthly demand ---
        $variation = $this->monthlyDemandVariation($tenantId, $from, $to);
        $xyzMap = [];
        foreach ($variation as $pid => $cv) {
            $xyzMap[$pid] = $cv < 10 ? 'X' : ($cv <= 25 ? 'Y' : 'Z');
        }

        // --- Persist (merge ABC + XYZ over union of product ids) ---
        $productIds = array_unique(array_merge(array_keys($abcMap), array_keys($xyzMap)));
        $counts = ['a' => 0, 'b' => 0, 'c' => 0, 'x' => 0, 'y' => 0, 'z' => 0];
        $upserted = 0;

        DB::transaction(function () use ($tenantId, $productIds, $abcMap, $xyzMap, $revenueShare, $variation, &$counts, &$upserted): void {
            $calculatedAt = now();
            foreach ($productIds as $pid) {
                $abc = $abcMap[$pid] ?? 'C';
                $xyz = $xyzMap[$pid] ?? 'Z';
                $counts[strtolower($abc)]++;
                $counts[strtolower($xyz)]++;

                ProductClassification::query()->withoutGlobalScopes()->updateOrCreate(
                    ['tenant_id' => $tenantId, 'product_id' => $pid],
                    [
                        'tenant_id' => $tenantId,
                        'product_id' => $pid,
                        'abc_class' => $abc,
                        'xyz_class' => $xyz,
                        'revenue_share' => round($revenueShare[$pid] ?? 0, 4),
                        'variation_coefficient' => round($variation[$pid] ?? 0, 4),
                        'calculated_at' => $calculatedAt,
                    ],
                );
                $upserted++;
            }
        });

        return [
            'upserted' => $upserted,
            'a' => $counts['a'],
            'b' => $counts['b'],
            'c' => $counts['c'],
            'x' => $counts['x'],
            'y' => $counts['y'],
            'z' => $counts['z'],
            'period_days' => $periodDays,
        ];
    }

    /**
     * Coefficient of variation (%) of monthly demand per product.
     *
     * @return array<int, float>  product_id => CV%
     */
    private function monthlyDemandVariation(int $tenantId, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $rows = StockLotDeduction::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('deducted_at', '>=', $from)
            ->where('deducted_at', '<=', $to)
            ->selectRaw('product_id, DATE_TRUNC(\'month\', deducted_at) AS month, SUM(quantity) AS qty')
            ->groupBy('product_id', 'month')
            ->get();

        $byProduct = [];
        foreach ($rows as $row) {
            $byProduct[$row->product_id][] = (float) $row->qty;
        }

        $result = [];
        foreach ($byProduct as $pid => $series) {
            $n = count($series);
            if ($n < 2) {
                // Single month of data → treat as stable (X) unless zero.
                $result[$pid] = $n === 0 ? 0.0 : 0.0;
                continue;
            }
            $mean = array_sum($series) / $n;
            if ($mean <= 0) {
                $result[$pid] = 0.0;
                continue;
            }
            $variance = array_sum(array_map(fn ($x) => ($x - $mean) ** 2, $series)) / $n;
            $std = sqrt($variance);
            $result[$pid] = round(($std / $mean) * 100, 4);
        }

        return $result;
    }
}
