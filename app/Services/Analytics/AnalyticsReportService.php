<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @package    Autometria\Services\Analytics
 * @copyright  (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author     Себиев Ахмед Сулейманович (Chief Software Architect / Lead Developer)
 * @license    Proprietary & Confidential.
 */

declare(strict_types=1);

namespace Autometria\Services\Analytics;

use Autometria\Models\Order;
use Autometria\Models\OrderItem;
use Autometria\Models\ProductService;
use Autometria\Models\Stock;
use Autometria\Models\StockBatch;
use Autometria\Models\StockLotDeduction;
use Autometria\Models\Warehouse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Аналитика и отчёты COGS (FIFO) для AUTOMETRIA ERP.
 *
 * Все методы строго фильтруют по tenant_id (RLS) и периоду.
 * COGS берётся из stock_lot_deductions (детализация FIFO-списаний партий).
 */
final class AnalyticsReportService
{
    /**
     * Сводка дашборда за период.
     */
    public function getDashboardSummary(
        int $tenantId,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?int $warehouseId = null,
    ): array {
        $revenue = $this->revenue($tenantId, $dateFrom, $dateTo, $warehouseId);
        $cogs = $this->cogs($tenantId, $dateFrom, $dateTo, $warehouseId);
        $grossProfit = round($revenue - $cogs, 2);
        $marginPct = $revenue > 0 ? round(($grossProfit / $revenue) * 100, 2) : 0.0;

        $ordersCount = $this->completedOrdersQuery($tenantId, $dateFrom, $dateTo, $warehouseId)->count();
        $avgCheck = $ordersCount > 0 ? round($revenue / $ordersCount, 2) : 0.0;

        // Динамика к предыдущему периоду (в %).
        $revenueDeltaPct = null;
        if ($dateFrom !== null && $dateTo !== null) {
            $prev = $this->previousPeriodBounds($dateFrom, $dateTo);
            $prevRevenue = $this->revenue($tenantId, $prev['from'], $prev['to'], $warehouseId);
            $revenueDeltaPct = $prevRevenue > 0 ? round((($revenue - $prevRevenue) / $prevRevenue) * 100, 2) : null;
        }

        return [
            'revenue' => $revenue,
            'cogs' => $cogs,
            'gross_profit' => $grossProfit,
            'margin_pct' => $marginPct,
            'avg_check' => $avgCheck,
            'orders_count' => $ordersCount,
            'revenue_delta_pct' => $revenueDeltaPct,
        ];
    }

    /**
     * Построчный отчёт COGS по позициям (товар, SKU, qty, revenue, COGS, margin).
     */
    public function getCogsBreakdown(
        int $tenantId,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?int $warehouseId = null,
    ): array {
        // Продажи по товарам (из order_items завершённых заказов).
        $sales = OrderItem::query()->withoutGlobalScopes()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.tenant_id', $tenantId)
            ->where('orders.status', \Autometria\Enums\OrderStatusEnum::COMPLETED->value)
            ->when($dateFrom, fn ($q) => $q->where('orders.created_at', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->where('orders.created_at', '<=', $dateTo))
            ->select(
                'order_items.product_id',
                DB::raw('SUM(order_items.qty) as qty_sold'),
                DB::raw('SUM(order_items.qty * order_items.price) as revenue'),
            )
            ->groupBy('order_items.product_id')
            ->get();

        $rows = [];
        foreach ($sales as $s) {
            $product = ProductService::query()->withoutGlobalScopes()->find($s->product_id);
            $cogs = $this->cogsForProduct($tenantId, (int) $s->product_id, $dateFrom, $dateTo, $warehouseId);
            $revenue = round((float) $s->revenue, 2);
            $grossProfit = round($revenue - $cogs, 2);
            $marginPct = $revenue > 0 ? round(($grossProfit / $revenue) * 100, 2) : 0.0;

            $rows[] = [
                'product_id' => (int) $s->product_id,
                'product_name' => $product?->name ?? '—',
                'sku' => $product?->article ?? null,
                'qty_sold' => round((float) $s->qty_sold, 3),
                'revenue' => $revenue,
                'cogs' => $cogs,
                'gross_profit' => $grossProfit,
                'margin_pct' => $marginPct,
            ];
        }

        return $rows;
    }

    /**
     * Матрица ABC/XYZ анализа (9 сегментов: AX..CZ).
     */
    public function getAbcXyzAnalysis(
        int $tenantId,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?int $warehouseId = null,
    ): array {
        // ABC: вклад в валовую прибыль по товарам.
        $cogsRows = $this->getCogsBreakdown($tenantId, $dateFrom, $dateTo, $warehouseId);
        $totalProfit = 0.0;
        foreach ($cogsRows as $r) {
            $totalProfit += $r['gross_profit'];
        }

        // Сортируем по убыванию валовой прибыли.
        usort($cogsRows, fn ($a, $b) => $b['gross_profit'] <=> $a['gross_profit']);

        $cum = 0.0;
        $abc = [];
        foreach ($cogsRows as $r) {
            $cum += $r['gross_profit'];
            $share = $totalProfit > 0 ? ($cum / $totalProfit) * 100 : 0;
            if ($share <= 80) {
                $abcClass = 'A';
            } elseif ($share <= 95) {
                $abcClass = 'B';
            } else {
                $abcClass = 'C';
            }
            $abc[$r['product_id']] = ['abc' => $abcClass, 'gross_profit' => $r['gross_profit']];
        }

        // XYZ: коэффициент вариации спроса по периодам (ежемесячно внутри диапазона).
        $xyz = $this->xyzByMonthlyDemand($tenantId, $dateFrom, $dateTo, $warehouseId);

        // Матрица 9 сегментов.
        $matrix = [];
        foreach ($cogsRows as $r) {
            $pid = $r['product_id'];
            $a = $abc[$pid]['abc'] ?? 'C';
            $z = $xyz[$pid] ?? 'Z';
            $segment = $a . $z;
            $matrix[$segment][] = [
                'product_id' => $pid,
                'product_name' => $r['product_name'],
                'gross_profit' => $r['gross_profit'],
            ];
        }

        return [
            'abc' => $abc,
            'xyz' => $xyz,
            'matrix' => $matrix,
        ];
    }

    /**
     * Оборачиваемость запасов и список неликвидов (deadstock > 60 дней).
     */
    public function getInventoryTurnover(
        int $tenantId,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?int $warehouseId = null,
    ): array {
        $cogs = $this->cogs($tenantId, $dateFrom, $dateTo, $warehouseId);

        // Средняя стоимость текущих остатков по себестоимости партий.
        $avgInventoryValue = $this->currentInventoryValue($tenantId, $warehouseId);
        $turnoverRatio = $avgInventoryValue > 0 ? round($cogs / $avgInventoryValue, 2) : 0.0;

        // Неликвиды: остатки без движения > 60 дней (нет списаний за последние 60 дней).
        $deadstockThreshold = now()->subDays(60);
        $deadstock = Stock::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->where('actual', '>', 0)
            ->whereDoesntHave('product', function ($q) use ($tenantId, $deadstockThreshold) {
                $q->whereHas('stockLotDeductions', function ($sq) use ($tenantId, $deadstockThreshold) {
                    $sq->where('tenant_id', $tenantId)
                        ->where('deducted_at', '>=', $deadstockThreshold);
                });
            })
            ->with('product')
            ->get()
            ->map(function (Stock $s) {
                return [
                    'product_id' => $s->product_id,
                    'product_name' => $s->product?->name,
                    'warehouse_id' => $s->warehouse_id,
                    'actual' => round((float) $s->actual, 3),
                ];
            })
            ->all();

        return [
            'cogs_period' => $cogs,
            'average_inventory_value' => round($avgInventoryValue, 2),
            'turnover_ratio' => $turnoverRatio,
            'deadstock' => $deadstock,
        ];
    }

    // ===== Внутренние хелперы =====

    private function revenue(int $tenantId, ?string $dateFrom, ?string $dateTo, ?int $warehouseId): float
    {
        return (float) $this->completedOrdersQuery($tenantId, $dateFrom, $dateTo, $warehouseId)
            ->sum('orders.total');
    }

    private function cogs(int $tenantId, ?string $dateFrom, ?string $dateTo, ?int $warehouseId): float
    {
        return (float) StockLotDeduction::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->when($dateFrom, fn ($q) => $q->where('deducted_at', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->where('deducted_at', '<=', $dateTo))
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->sum('total_cost');
    }

    private function cogsForProduct(int $tenantId, int $productId, ?string $dateFrom, ?string $dateTo, ?int $warehouseId): float
    {
        return (float) StockLotDeduction::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('product_id', $productId)
            ->when($dateFrom, fn ($q) => $q->where('deducted_at', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->where('deducted_at', '<=', $dateTo))
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->sum('total_cost');
    }

    private function completedOrdersQuery(int $tenantId, ?string $dateFrom, ?string $dateTo, ?int $warehouseId)
    {
        return Order::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('status', \Autometria\Enums\OrderStatusEnum::COMPLETED->value)
            ->when($dateFrom, fn ($q) => $q->where('created_at', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->where('created_at', '<=', $dateTo));
    }

    private function currentInventoryValue(int $tenantId, ?int $warehouseId): float
    {
        // Сумма actual * cost_price по всем партиям.
        return (float) StockBatch::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->select(DB::raw('COALESCE(SUM(remaining_qty * cost_price), 0) as val'))
            ->value('val');
    }

    /**
     * XYZ: коэффициент вариации спроса по месяцам внутри диапазона.
     */
    private function xyzByMonthlyDemand(int $tenantId, ?string $dateFrom, ?string $dateTo, ?int $warehouseId): array
    {
        $from = $dateFrom ?: now()->subYear()->toDateString();
        $to = $dateTo ?: now()->toDateString();

        $rows = StockLotDeduction::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('deducted_at', '>=', $from)
            ->where('deducted_at', '<=', $to)
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->select('product_id', DB::raw("TO_CHAR(deducted_at, 'YYYY-MM') as month"), DB::raw('SUM(quantity) as qty'))
            ->groupBy('product_id', DB::raw("TO_CHAR(deducted_at, 'YYYY-MM')"))
            ->get();

        $byProduct = [];
        foreach ($rows as $r) {
            $byProduct[$r->product_id][] = (float) $r->qty;
        }

        $result = [];
        foreach ($byProduct as $pid => $demands) {
            $n = count($demands);
            if ($n < 2) {
                $result[$pid] = 'Z'; // недостаточно данных → эпизодический
                continue;
            }
            $mean = array_sum($demands) / $n;
            $variance = 0.0;
            foreach ($demands as $d) {
                $variance += ($d - $mean) ** 2;
            }
            $std = sqrt($variance / $n);
            $cv = $mean > 0 ? ($std / $mean) * 100 : 0;

            if ($cv < 10) {
                $result[$pid] = 'X';
            } elseif ($cv <= 25) {
                $result[$pid] = 'Y';
            } else {
                $result[$pid] = 'Z';
            }
        }

        return $result;
    }

    private function previousPeriodBounds(?string $dateFrom, ?string $dateTo): array
    {
        if (! $dateFrom || ! $dateTo) {
            $to = \Carbon\Carbon::parse($dateTo ?: now());
            $from = \Carbon\Carbon::parse($dateFrom ?: (string) now()->subMonth());
            $length = (int) $from->diffInDays($to);
        } else {
            $from = \Carbon\Carbon::parse($dateFrom);
            $to = \Carbon\Carbon::parse($dateTo);
            $length = (int) $from->diffInDays($to);
        }
        $length = max(1, $length);
        $prevTo = $from->copy()->subDay();
        $prevFrom = $prevTo->copy()->subDays($length);

        return ['from' => $prevFrom->toDateString(), 'to' => $prevTo->toDateString()];
    }
}
