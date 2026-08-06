<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Services\Inventory;

use Autometria\Models\ProductService;
use Autometria\Models\Stock;
use Autometria\Services\Traits\BcMathDecimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Demand forecasting: ROP = (d_avg × L) + SS, dead stock, light seasonality — BCMath only.
 */
final class InventoryDemandPredictor
{
    use BcMathDecimal;

    /** z≈1.65 for ~95% service level (string, no float math on decision path). */
    private const Z_SCORE = '1.650';

    /**
     * @return list<array{
     *   product_id: int,
     *   warehouse_id: int,
     *   sku: string,
     *   name: string,
     *   d_avg: string,
     *   safety_stock: string,
     *   rop: string,
     *   on_hand: string,
     *   suggested_qty: string,
     *   is_dead_stock: bool,
     *   severity: string,
     *   lead_time_days: int,
     *   lookback_days: int
     * }>
     */
    public function predict(
        int $tenantId,
        ?int $warehouseId = null,
        int $lookbackDays = 30,
        int $leadTimeDays = 7,
        int $deadStockDays = 90,
    ): array {
        $lookbackDays = max(1, $lookbackDays);
        $leadTimeDays = max(1, $leadTimeDays);
        $deadStockDays = max($lookbackDays, $deadStockDays);

        $demand = $this->aggregateDailyDemand($tenantId, $warehouseId, $lookbackDays);
        $onHandMap = $this->onHandByProductWarehouse($tenantId, $warehouseId);
        $lastSaleMap = $this->lastSaleAt($tenantId, $warehouseId);
        $season = $this->seasonalityFactor();

        $productIds = $demand->keys()
            ->map(fn ($k) => (int) explode(':', (string) $k)[0])
            ->merge($onHandMap->keys()->map(fn ($k) => (int) explode(':', (string) $k)[0]))
            ->unique()
            ->filter(fn ($id) => $id > 0)
            ->values()
            ->all();

        $products = ProductService::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $productIds ?: [0])
            ->get(['id', 'name', 'article', 'brand'])
            ->keyBy('id');

        $out = [];
        $seen = [];

        foreach ($onHandMap as $key => $onHand) {
            [$pid, $wid] = array_map('intval', explode(':', (string) $key));
            $seen[$key] = true;

            $stats = $demand->get($key, ['total' => '0.000', 'day_count' => 0, 'variance' => '0.000']);
            $row = $this->buildRow(
                $pid,
                $wid,
                $products->get($pid),
                (string) $stats['total'],
                (int) $stats['day_count'],
                (string) $stats['variance'],
                (string) $onHand,
                $lookbackDays,
                $leadTimeDays,
                $deadStockDays,
                $lastSaleMap->get($key),
                $season,
            );
            $out[] = $row;
        }

        // Include demand-only keys (sold but zero stock) for completeness.
        foreach ($demand as $key => $stats) {
            if (isset($seen[$key])) {
                continue;
            }
            [$pid, $wid] = array_map('intval', explode(':', (string) $key));
            $out[] = $this->buildRow(
                $pid,
                $wid,
                $products->get($pid),
                (string) $stats['total'],
                (int) $stats['day_count'],
                (string) $stats['variance'],
                '0.000',
                $lookbackDays,
                $leadTimeDays,
                $deadStockDays,
                $lastSaleMap->get($key),
                $season,
            );
        }

        usort($out, function (array $a, array $b): int {
            $rank = ['critical' => 0, 'warn' => 1, 'ok' => 2];
            $ra = $rank[$a['severity']] ?? 9;
            $rb = $rank[$b['severity']] ?? 9;
            if ($ra !== $rb) {
                return $ra <=> $rb;
            }

            return $this->bcComp($b['suggested_qty'], $a['suggested_qty'], 3);
        });

        return $out;
    }

    /**
     * Pure BCMath ROP helper for unit tests.
     *
     * @return array{d_avg: string, safety_stock: string, rop: string, suggested_qty: string}
     */
    public function computeRop(
        string $totalSold,
        int $lookbackDays,
        int $leadTimeDays,
        string $demandVariance,
        string $onHand,
        string $seasonFactor = '1.000',
    ): array {
        $lookbackDays = max(1, $lookbackDays);
        $leadTimeDays = max(1, $leadTimeDays);

        $dAvg = $this->bcDiv($totalSold, (string) $lookbackDays, 3);
        $dAvg = $this->bcMul($dAvg, $seasonFactor, 3);

        // SS = z * σ * √L  (√L via string table / bcSqrt-ish approximation using bcmath loop)
        $sigma = $this->bcSqrt($demandVariance, 3);
        $sqrtL = $this->bcSqrt((string) $leadTimeDays, 3);
        $ss = $this->bcMul($this->bcMul(self::Z_SCORE, $sigma, 3), $sqrtL, 3);

        $rop = $this->bcAdd($this->bcMul($dAvg, (string) $leadTimeDays, 3), $ss, 3);
        $suggested = $this->bcMax($this->bcSub($rop, $onHand, 3), '0', 3);

        return [
            'd_avg' => $dAvg,
            'safety_stock' => $ss,
            'rop' => $rop,
            'suggested_qty' => $suggested,
        ];
    }

    /**
     * @return Collection<string, array{total: string, day_count: int, variance: string}>
     */
    private function aggregateDailyDemand(int $tenantId, ?int $warehouseId, int $lookbackDays): Collection
    {
        $from = now()->subDays($lookbackDays)->startOfDay();

        $q = DB::table('stock_lot_deductions')
            ->select([
                'product_id',
                'warehouse_id',
                DB::raw("DATE(deducted_at) as day"),
                DB::raw('SUM(quantity - COALESCE(refunded_qty, 0)) as day_qty'),
            ])
            ->where('tenant_id', $tenantId)
            ->where('deducted_at', '>=', $from)
            ->groupBy('product_id', 'warehouse_id', DB::raw('DATE(deducted_at)'));

        if ($warehouseId !== null) {
            $q->where('warehouse_id', $warehouseId);
        }

        $rows = $q->get();

        /** @var array<string, list<string>> $series */
        $series = [];
        foreach ($rows as $row) {
            $key = ((int) $row->product_id).':'.((int) $row->warehouse_id);
            $qty = $this->bcNormalize((string) $row->day_qty, 3);
            if ($this->bcComp($qty, '0', 3) < 0) {
                $qty = '0.000';
            }
            $series[$key][] = $qty;
        }

        $out = collect();
        foreach ($series as $key => $days) {
            $total = '0.000';
            foreach ($days as $q) {
                $total = $this->bcAdd($total, $q, 3);
            }
            $n = count($days);
            $mean = $this->bcDiv($total, (string) max(1, $n), 3);
            $varAcc = '0.000';
            foreach ($days as $q) {
                $diff = $this->bcSub($q, $mean, 3);
                $varAcc = $this->bcAdd($varAcc, $this->bcMul($diff, $diff, 6), 6);
            }
            $variance = $this->bcDiv($varAcc, (string) max(1, $n), 3);
            $out->put($key, [
                'total' => $total,
                'day_count' => $n,
                'variance' => $variance,
            ]);
        }

        return $out;
    }

    /**
     * @return Collection<string, string> key product:warehouse => on_hand
     */
    private function onHandByProductWarehouse(int $tenantId, ?int $warehouseId): Collection
    {
        $q = Stock::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->select(['product_id', 'warehouse_id', 'available']);

        if ($warehouseId !== null) {
            $q->where('warehouse_id', $warehouseId);
        }

        $map = collect();
        foreach ($q->get() as $row) {
            $key = ((int) $row->product_id).':'.((int) $row->warehouse_id);
            $map->put($key, $this->bcNormalize((string) $row->available, 3));
        }

        return $map;
    }

    /**
     * @return Collection<string, \Carbon\Carbon|string|null>
     */
    private function lastSaleAt(int $tenantId, ?int $warehouseId): Collection
    {
        $q = DB::table('stock_lot_deductions')
            ->select(['product_id', 'warehouse_id', DB::raw('MAX(deducted_at) as last_at')])
            ->where('tenant_id', $tenantId)
            ->groupBy('product_id', 'warehouse_id');

        if ($warehouseId !== null) {
            $q->where('warehouse_id', $warehouseId);
        }

        $map = collect();
        foreach ($q->get() as $row) {
            $key = ((int) $row->product_id).':'.((int) $row->warehouse_id);
            $map->put($key, $row->last_at);
        }

        return $map;
    }

    private function seasonalityFactor(): string
    {
        // Light monthly seasonality (string factors). Peak spring/autumn tyre season.
        $month = (int) now()->format('n');
        $map = [
            1 => '0.850', 2 => '0.900', 3 => '1.150', 4 => '1.200',
            5 => '1.050', 6 => '0.950', 7 => '0.900', 8 => '0.950',
            9 => '1.100', 10 => '1.250', 11 => '1.150', 12 => '0.900',
        ];

        return $map[$month] ?? '1.000';
    }

    /**
     * @param  \Autometria\Models\ProductService|null  $product
     * @return array<string, mixed>
     */
    private function buildRow(
        int $productId,
        int $warehouseId,
        $product,
        string $totalSold,
        int $dayCount,
        string $variance,
        string $onHand,
        int $lookbackDays,
        int $leadTimeDays,
        int $deadStockDays,
        mixed $lastSaleAt,
        string $season,
    ): array {
        // Spread sparse sales over full lookback for d_avg stability.
        $ropParts = $this->computeRop($totalSold, $lookbackDays, $leadTimeDays, $variance, $onHand, $season);

        $isDead = false;
        if ($this->bcComp($onHand, '0', 3) > 0) {
            if ($lastSaleAt === null) {
                $isDead = true;
            } else {
                $last = \Carbon\Carbon::parse((string) $lastSaleAt);
                $isDead = $last->lt(now()->subDays($deadStockDays));
            }
        }

        $severity = 'ok';
        if ($this->bcComp($onHand, $ropParts['rop'], 3) < 0) {
            $severity = $this->bcComp($onHand, $this->bcMul($ropParts['rop'], '0.500', 3), 3) < 0
                ? 'critical'
                : 'warn';
        }
        if ($isDead && $severity === 'ok') {
            $severity = 'warn';
        }

        return [
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
            'sku' => (string) ($product?->article ?: "ID-{$productId}"),
            'name' => (string) ($product?->name ?: "Product #{$productId}"),
            'd_avg' => $ropParts['d_avg'],
            'safety_stock' => $ropParts['safety_stock'],
            'rop' => $ropParts['rop'],
            'on_hand' => $this->bcNormalize($onHand, 3),
            'suggested_qty' => $ropParts['suggested_qty'],
            'is_dead_stock' => $isDead,
            'severity' => $severity,
            'lead_time_days' => $leadTimeDays,
            'lookback_days' => $lookbackDays,
            '_day_count' => $dayCount,
        ];
    }

    /**
     * Newton's method square root via bcmath (no float).
     */
    private function bcSqrt(string $value, int $scale = 3): string
    {
        $value = $this->bcNormalize($value, $scale + 2);
        if ($this->bcComp($value, '0', $scale) <= 0) {
            return $this->bcNormalize('0', $scale);
        }

        $x = $value;
        for ($i = 0; $i < 12; $i++) {
            // x = (x + value/x) / 2
            $x = $this->bcDiv(
                $this->bcAdd($x, $this->bcDiv($value, $x, $scale + 4), $scale + 4),
                '2',
                $scale + 4,
            );
        }

        return $this->bcNormalize($x, $scale);
    }
}
