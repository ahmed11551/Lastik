<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Services\Analytics;

use Autometria\Models\ProductService;
use Autometria\Models\Stock;
use Autometria\Models\StockLotDeduction;
use Illuminate\Support\Collection;

/**
 * Demand forecasting & Reorder Point (ROP) engine (v1.4.0 Sprint 2).
 *
 * ROP = (Avg Daily Sales × Lead Time) + Safety Stock
 * is_stockout_risk = (Current Stock ≤ ROP)
 */
final class DemandForecasterService
{
    /**
     * @return array{
     *     product_id: int,
     *     avg_daily_sales: float,
     *     lead_time_days: int,
     *     safety_stock: float,
     *     reorder_point: float,
     *     current_stock: float,
     *     is_stockout_risk: bool,
     * }
     */
    public function forecast(int $tenantId, int $productId, int $lookbackDays = 90): array
    {
        $product = ProductService::query()->withoutGlobalScopes()->findOrFail($productId);

        $avgDailySales = $this->avgDailySales($tenantId, $productId, $lookbackDays);
        $leadTimeDays = (int) ($product->lead_time_days ?? 0);
        $safetyStock = (float) ($product->safety_stock ?? 0);
        $currentStock = $this->currentStock($tenantId, $productId);

        $reorderPoint = round(($avgDailySales * $leadTimeDays) + $safetyStock, 3);

        return [
            'product_id' => $productId,
            'avg_daily_sales' => round($avgDailySales, 4),
            'lead_time_days' => $leadTimeDays,
            'safety_stock' => round($safetyStock, 3),
            'reorder_point' => $reorderPoint,
            'current_stock' => round($currentStock, 3),
            'is_stockout_risk' => $currentStock <= $reorderPoint,
        ];
    }

    /**
     * @return Collection<int, array{product_id: int, is_stockout_risk: bool, reorder_point: float, current_stock: float}>
     */
    public function riskScan(int $tenantId, int $lookbackDays = 90): Collection
    {
        $products = ProductService::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->get();

        return $products->map(function (ProductService $p) use ($tenantId, $lookbackDays): array {
            $f = $this->forecast($tenantId, $p->id, $lookbackDays);

            return [
                'product_id' => $p->id,
                'is_stockout_risk' => $f['is_stockout_risk'],
                'reorder_point' => $f['reorder_point'],
                'current_stock' => $f['current_stock'],
            ];
        })->filter(fn ($r) => $r['is_stockout_risk']);
    }

    private function avgDailySales(int $tenantId, int $productId, int $lookbackDays): float
    {
        $from = now()->subDays($lookbackDays)->startOfDay();
        $to = now();

        $qty = (float) StockLotDeduction::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('product_id', $productId)
            ->where('deducted_at', '>=', $from)
            ->where('deducted_at', '<=', $to)
            ->sum('quantity');

        return $lookbackDays > 0 ? $qty / $lookbackDays : 0.0;
    }

    private function currentStock(int $tenantId, int $productId): float
    {
        return (float) Stock::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('product_id', $productId)
            ->sum('actual');
    }
}
