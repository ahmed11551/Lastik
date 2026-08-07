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

use Autometria\Enums\OrderStatusEnum;
use Autometria\Enums\PaymentStatusEnum;
use Autometria\Enums\RefundStatusEnum;
use Autometria\Models\Order;
use Autometria\Models\OrderItem;
use Autometria\Models\ProductService;
use Autometria\Models\RefundItem;
use Autometria\Models\Stock;
use Autometria\Models\StockBatch;
use Autometria\Models\StockLotDeduction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Аналитика и отчёты COGS (FIFO) для AUTOMETRIA ERP.
 *
 * Выручка: оплаченные продажи (paid/partial) + legacy completed-статусы, минус возвраты.
 * COGS: net FIFO = total_cost − refunded_qty × unit_cost.
 */
final class AnalyticsReportService
{
    /**
     * Сводка дашборда за период.
     *
     * revenue / net_revenue = Gross Sales − Refunds.
     * cogs = net FIFO (stock_lot_deductions с учётом refunded_qty).
     * gross_profit / net_profit = Net Revenue − COGS (opex пока не учитывается).
     */
    public function getDashboardSummary(
        int $tenantId,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?int $warehouseId = null,
    ): array {
        $dateTo = $this->normalizeDateTo($dateTo);
        $grossSales = $this->grossSales($tenantId, $dateFrom, $dateTo, $warehouseId);
        $refundsTotal = $this->refundsTotal($tenantId, $dateFrom, $dateTo, $warehouseId);
        $revenue = round($grossSales - $refundsTotal, 2);
        $cogs = $this->cogs($tenantId, $dateFrom, $dateTo, $warehouseId);
        $grossProfit = round($revenue - $cogs, 2);
        $marginPct = $revenue > 0 ? round(($grossProfit / $revenue) * 100, 2) : 0.0;

        $ordersCount = $this->soldOrdersQuery($tenantId, $dateFrom, $dateTo, $warehouseId)->count();
        $avgCheck = $ordersCount > 0 ? round($revenue / $ordersCount, 2) : 0.0;

        $revenueDeltaPct = null;
        if ($dateFrom !== null && $dateTo !== null) {
            $prev = $this->previousPeriodBounds($dateFrom, $dateTo);
            $prevTo = $this->normalizeDateTo($prev['to']);
            $prevGross = $this->grossSales($tenantId, $prev['from'], $prevTo, $warehouseId);
            $prevRefunds = $this->refundsTotal($tenantId, $prev['from'], $prevTo, $warehouseId);
            $prevRevenue = round($prevGross - $prevRefunds, 2);
            $revenueDeltaPct = $prevRevenue > 0
                ? round((($revenue - $prevRevenue) / $prevRevenue) * 100, 2)
                : null;
        }

        return [
            'gross_sales' => $grossSales,
            'refunds_total' => $refundsTotal,
            'revenue' => $revenue,
            'net_revenue' => $revenue,
            'cogs' => $cogs,
            'gross_profit' => $grossProfit,
            'net_profit' => $grossProfit,
            'margin_pct' => $marginPct,
            'avg_check' => $avgCheck,
            'orders_count' => $ordersCount,
            'revenue_delta_pct' => $revenueDeltaPct,
        ];
    }

    /**
     * Сводный отчёт Block 4.1: Revenue, COGS, Profit, Margin, Top Products, Turnover, Stock Value.
     */
    public function getDashboard(
        int $tenantId,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?int $warehouseId = null,
        int $topLimit = 10,
    ): array {
        $summary = $this->getDashboardSummary($tenantId, $dateFrom, $dateTo, $warehouseId);
        $turnover = $this->getInventoryTurnover($tenantId, $dateFrom, $dateTo, $warehouseId);
        $stockValue = round($this->currentInventoryValue($tenantId, $warehouseId), 2);

        $breakdown = $this->getCogsBreakdown($tenantId, $dateFrom, $dateTo, $warehouseId);
        usort($breakdown, fn ($a, $b) => $b['gross_profit'] <=> $a['gross_profit']);
        $topProducts = array_slice($breakdown, 0, max(1, $topLimit));

        $abcXyz = $this->getAbcXyzAnalysis($tenantId, $dateFrom, $dateTo, $warehouseId);

        return [
            'period' => [
                'from' => $dateFrom,
                'to' => $dateTo,
            ],
            'gross_sales' => $summary['gross_sales'],
            'refunds_total' => $summary['refunds_total'],
            'revenue' => $summary['revenue'],
            'net_revenue' => $summary['net_revenue'],
            'cogs' => $summary['cogs'],
            'gross_profit' => $summary['gross_profit'],
            'net_profit' => $summary['net_profit'],
            'margin_pct' => $summary['margin_pct'],
            'avg_check' => $summary['avg_check'],
            'orders_count' => $summary['orders_count'],
            'revenue_delta_pct' => $summary['revenue_delta_pct'],
            'turnover_rate' => $turnover['turnover_ratio'],
            'average_inventory_value' => $turnover['average_inventory_value'],
            'stock_value' => $stockValue,
            'stock_valuation_at_cost' => $stockValue,
            'inventory_value_basis' => $turnover['inventory_value_basis'],
            'top_products' => $topProducts,
            'abc_xyz' => $abcXyz,
            'deadstock' => $turnover['deadstock'],
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
        $dateTo = $this->normalizeDateTo($dateTo);
        // Продажи по товарам (из order_items завершённых/оплаченных заказов).
        $sales = OrderItem::query()->withoutGlobalScopes()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.tenant_id', $tenantId)
            ->where(function ($q) {
                $this->applySoldOrderConstraints($q, 'orders');
            })
            ->when($dateFrom, fn ($q) => $q->where('orders.created_at', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->where('orders.created_at', '<=', $dateTo))
            ->when($warehouseId, function ($q) use ($warehouseId) {
                $q->where(function ($inner) use ($warehouseId) {
                    $inner->whereRaw(
                        "(order_items.snapshot->>'warehouse_id')::int = ?",
                        [$warehouseId],
                    )->orWhereExists(function ($sq) use ($warehouseId) {
                        $sq->selectRaw('1')
                            ->from('stock_lot_deductions')
                            ->whereColumn('stock_lot_deductions.order_item_id', 'order_items.id')
                            ->where('stock_lot_deductions.warehouse_id', $warehouseId);
                    });
                });
            })
            ->select(
                'order_items.product_id',
                DB::raw('SUM(order_items.qty) as qty_sold'),
                DB::raw('SUM(order_items.qty * order_items.price) as revenue'),
            )
            ->groupBy('order_items.product_id')
            ->get();

        $refundByProduct = $this->refundQtyAndAmountByProduct($tenantId, $dateFrom, $dateTo, $warehouseId);

        $rows = [];
        foreach ($sales as $s) {
            if ($s->product_id === null) {
                continue;
            }
            $productId = (int) $s->product_id;
            $product = ProductService::query()->withoutGlobalScopes()->find($productId);
            $refund = $refundByProduct[$productId] ?? ['qty' => 0.0, 'amount' => 0.0];
            $cogs = $this->cogsForProduct($tenantId, $productId, $dateFrom, $dateTo, $warehouseId);
            $revenue = round((float) $s->revenue - $refund['amount'], 2);
            $qtySold = round((float) $s->qty_sold - $refund['qty'], 3);
            $grossProfit = round($revenue - $cogs, 2);
            $marginPct = $revenue > 0 ? round(($grossProfit / $revenue) * 100, 2) : 0.0;

            $rows[] = [
                'product_id' => $productId,
                'product_name' => $product?->name ?? '—',
                'sku' => $product?->article ?? null,
                'qty_sold' => $qtySold,
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
        $cogsRows = $this->getCogsBreakdown($tenantId, $dateFrom, $dateTo, $warehouseId);
        $dateTo = $this->normalizeDateTo($dateTo);
        $totalProfit = 0.0;
        foreach ($cogsRows as $r) {
            $totalProfit += $r['gross_profit'];
        }

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

        $xyzMeta = $this->xyzByMonthlyDemand($tenantId, $dateFrom, $dateTo, $warehouseId);

        $matrix = [];
        $rows = [];
        foreach ($cogsRows as $r) {
            $pid = $r['product_id'];
            $a = $abc[$pid]['abc'] ?? 'C';
            $meta = $xyzMeta[$pid] ?? ['xyz' => 'Z', 'cv' => null];
            $z = $meta['xyz'] ?? 'Z';
            $segment = $a.$z;
            $sharePct = $totalProfit > 0
                ? round(($r['gross_profit'] / $totalProfit) * 100, 2)
                : 0.0;

            $item = [
                'product_id' => $pid,
                'product_name' => $r['product_name'],
                'gross_profit' => $r['gross_profit'],
                'revenue' => $r['revenue'] ?? $r['gross_profit'],
                'share_pct' => $sharePct,
                'cv' => $meta['cv'],
                'abc' => $a,
                'xyz' => $z,
                'segment' => $segment,
            ];
            $matrix[$segment][] = $item;
            $rows[] = $item;
        }

        $cells = [];
        foreach (['A', 'B', 'C'] as $abcClass) {
            foreach (['X', 'Y', 'Z'] as $xyzClass) {
                $key = $abcClass.$xyzClass;
                $items = $matrix[$key] ?? [];
                $cellProfit = 0.0;
                foreach ($items as $it) {
                    $cellProfit += (float) $it['gross_profit'];
                }
                $cells[$key] = [
                    'segment' => $key,
                    'abc' => $abcClass,
                    'xyz' => $xyzClass,
                    'count' => count($items),
                    'gross_profit' => round($cellProfit, 2),
                    'share_pct' => $totalProfit > 0 ? round(($cellProfit / $totalProfit) * 100, 2) : 0.0,
                ];
            }
        }

        $xyz = [];
        foreach ($xyzMeta as $pid => $meta) {
            $xyz[$pid] = $meta['xyz'] ?? 'Z';
        }

        return [
            'abc' => $abc,
            'xyz' => $xyz,
            'matrix' => $matrix,
            'cells' => $cells,
            'rows' => $rows,
            'total_gross_profit' => round($totalProfit, 2),
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
        $dateTo = $this->normalizeDateTo($dateTo);
        $cogs = $this->cogs($tenantId, $dateFrom, $dateTo, $warehouseId);

        // Текущая стоимость остатков (не истинное среднее за период) — без овердрафт-партий.
        $avgInventoryValue = $this->currentInventoryValue($tenantId, $warehouseId);
        $turnoverRatio = $avgInventoryValue > 0 ? round($cogs / $avgInventoryValue, 2) : 0.0;

        $deadstockThreshold = now()->subDays(60);
        $recentProductIds = StockLotDeduction::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('deducted_at', '>=', $deadstockThreshold)
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->distinct()
            ->pluck('product_id')
            ->all();

        $deadstock = Stock::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->where('actual', '>', 0)
            ->when($recentProductIds !== [], fn ($q) => $q->whereNotIn('product_id', $recentProductIds))
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
            'stock_valuation_at_cost' => round($avgInventoryValue, 2),
            'inventory_value_basis' => 'current',
            'turnover_ratio' => $turnoverRatio,
            'deadstock' => $deadstock,
        ];
    }

    /**
     * Дневной ряд: выручка / COGS / валовая прибыль.
     *
     * @return list<array{date: string, revenue: float, cogs: float, gross_profit: float}>
     */
    public function getSalesSeries(
        int $tenantId,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?int $warehouseId = null,
    ): array {
        $from = $dateFrom ?: now()->subDays(29)->toDateString();
        $toDate = $dateTo ?: now()->toDateString();
        $to = $this->normalizeDateTo($toDate);

        $revenueByDay = [];
        $orders = $this->soldOrdersQuery($tenantId, $from, $to, $warehouseId)
            ->select('orders.id', 'orders.total', 'orders.created_at')
            ->get();

        if ($warehouseId === null) {
            foreach ($orders as $order) {
                $day = $order->created_at?->toDateString() ?? $from;
                $revenueByDay[$day] = ($revenueByDay[$day] ?? 0.0) + (float) $order->total;
            }
        } else {
            $lineRows = OrderItem::query()->withoutGlobalScopes()
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->where('orders.tenant_id', $tenantId)
                ->where(function ($q) {
                    $this->applySoldOrderConstraints($q, 'orders');
                })
                ->where('orders.created_at', '>=', $from)
                ->where('orders.created_at', '<=', $to)
                ->where(function ($q) use ($warehouseId) {
                    $q->whereRaw("(order_items.snapshot->>'warehouse_id')::int = ?", [$warehouseId])
                        ->orWhereExists(function ($sq) use ($warehouseId) {
                            $sq->selectRaw('1')
                                ->from('stock_lot_deductions')
                                ->whereColumn('stock_lot_deductions.order_item_id', 'order_items.id')
                                ->where('stock_lot_deductions.warehouse_id', $warehouseId);
                        });
                })
                ->select(
                    DB::raw('DATE(orders.created_at) as day'),
                    DB::raw('SUM(order_items.qty * order_items.price) as revenue'),
                )
                ->groupBy(DB::raw('DATE(orders.created_at)'))
                ->get();
            foreach ($lineRows as $row) {
                $revenueByDay[(string) $row->day] = (float) $row->revenue;
            }
        }

        $refundRows = RefundItem::query()->withoutGlobalScopes()
            ->join('refunds', 'refunds.id', '=', 'refund_items.refund_id')
            ->where('refunds.tenant_id', $tenantId)
            ->where('refunds.status', RefundStatusEnum::COMPLETED->value)
            ->where('refunds.created_at', '>=', $from)
            ->where('refunds.created_at', '<=', $to)
            ->when($warehouseId, function ($q) use ($warehouseId) {
                $q->whereExists(function ($sq) use ($warehouseId) {
                    $sq->selectRaw('1')
                        ->from('stock_lot_deductions')
                        ->whereColumn('stock_lot_deductions.order_item_id', 'refund_items.order_item_id')
                        ->where('stock_lot_deductions.warehouse_id', $warehouseId);
                });
            })
            ->select(
                DB::raw('DATE(refunds.created_at) as day'),
                DB::raw('SUM(refund_items.amount) as amount'),
            )
            ->groupBy(DB::raw('DATE(refunds.created_at)'))
            ->get();
        foreach ($refundRows as $row) {
            $day = (string) $row->day;
            $revenueByDay[$day] = ($revenueByDay[$day] ?? 0.0) - (float) $row->amount;
        }

        $cogsRows = StockLotDeduction::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('deducted_at', '>=', $from)
            ->where('deducted_at', '<=', $to)
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->select(
                DB::raw('DATE(deducted_at) as day'),
                DB::raw('COALESCE(SUM(total_cost - COALESCE(refunded_qty, 0) * unit_cost), 0) as cogs'),
            )
            ->groupBy(DB::raw('DATE(deducted_at)'))
            ->get();
        $cogsByDay = [];
        foreach ($cogsRows as $row) {
            $cogsByDay[(string) $row->day] = round((float) $row->cogs, 2);
        }

        $cursor = \Carbon\Carbon::parse($from)->startOfDay();
        $end = \Carbon\Carbon::parse($toDate)->startOfDay();
        $series = [];
        while ($cursor->lte($end)) {
            $day = $cursor->toDateString();
            $rev = round($revenueByDay[$day] ?? 0.0, 2);
            $cogs = round($cogsByDay[$day] ?? 0.0, 2);
            $series[] = [
                'date' => $day,
                'revenue' => $rev,
                'cogs' => $cogs,
                'gross_profit' => round($rev - $cogs, 2),
            ];
            $cursor->addDay();
        }

        return $series;
    }

    private function normalizeDateTo(?string $dateTo): ?string
    {
        if ($dateTo === null || $dateTo === '') {
            return $dateTo;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo) === 1) {
            return $dateTo.' 23:59:59';
        }

        return $dateTo;
    }

    // ===== Внутренние хелперы =====

    /** Net Revenue = Gross Sales − Refunds. */
    private function revenue(int $tenantId, ?string $dateFrom, ?string $dateTo, ?int $warehouseId): float
    {
        return round(
            $this->grossSales($tenantId, $dateFrom, $dateTo, $warehouseId)
            - $this->refundsTotal($tenantId, $dateFrom, $dateTo, $warehouseId),
            2,
        );
    }

    private function grossSales(int $tenantId, ?string $dateFrom, ?string $dateTo, ?int $warehouseId): float
    {
        if ($warehouseId === null) {
            return round((float) $this->soldOrdersQuery($tenantId, $dateFrom, $dateTo, null)
                ->sum('orders.total'), 2);
        }

        return round((float) OrderItem::query()->withoutGlobalScopes()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.tenant_id', $tenantId)
            ->where(function ($q) {
                $this->applySoldOrderConstraints($q, 'orders');
            })
            ->when($dateFrom, fn ($q) => $q->where('orders.created_at', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->where('orders.created_at', '<=', $dateTo))
            ->where(function ($q) use ($warehouseId) {
                $q->whereRaw("(order_items.snapshot->>'warehouse_id')::int = ?", [$warehouseId])
                    ->orWhereExists(function ($sq) use ($warehouseId) {
                        $sq->selectRaw('1')
                            ->from('stock_lot_deductions')
                            ->whereColumn('stock_lot_deductions.order_item_id', 'order_items.id')
                            ->where('stock_lot_deductions.warehouse_id', $warehouseId);
                    });
            })
            ->sum(DB::raw('order_items.qty * order_items.price')), 2);
    }

    private function refundsTotal(int $tenantId, ?string $dateFrom, ?string $dateTo, ?int $warehouseId): float
    {
        return round((float) RefundItem::query()->withoutGlobalScopes()
            ->join('refunds', 'refunds.id', '=', 'refund_items.refund_id')
            ->where('refunds.tenant_id', $tenantId)
            ->where('refunds.status', RefundStatusEnum::COMPLETED->value)
            ->when($dateFrom, fn ($q) => $q->where('refunds.created_at', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->where('refunds.created_at', '<=', $dateTo))
            ->when($warehouseId, function ($q) use ($warehouseId) {
                $q->whereExists(function ($sq) use ($warehouseId) {
                    $sq->selectRaw('1')
                        ->from('stock_lot_deductions')
                        ->whereColumn('stock_lot_deductions.order_item_id', 'refund_items.order_item_id')
                        ->where('stock_lot_deductions.warehouse_id', $warehouseId);
                });
            })
            ->sum('refund_items.amount'), 2);
    }

    /**
     * @return array<int, array{qty: float, amount: float}>
     */
    private function refundQtyAndAmountByProduct(
        int $tenantId,
        ?string $dateFrom,
        ?string $dateTo,
        ?int $warehouseId,
    ): array {
        $rows = RefundItem::query()->withoutGlobalScopes()
            ->join('refunds', 'refunds.id', '=', 'refund_items.refund_id')
            ->where('refunds.tenant_id', $tenantId)
            ->where('refunds.status', RefundStatusEnum::COMPLETED->value)
            ->when($dateFrom, fn ($q) => $q->where('refunds.created_at', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->where('refunds.created_at', '<=', $dateTo))
            ->when($warehouseId, function ($q) use ($warehouseId) {
                $q->whereExists(function ($sq) use ($warehouseId) {
                    $sq->selectRaw('1')
                        ->from('stock_lot_deductions')
                        ->whereColumn('stock_lot_deductions.order_item_id', 'refund_items.order_item_id')
                        ->where('stock_lot_deductions.warehouse_id', $warehouseId);
                });
            })
            ->whereNotNull('refund_items.product_id')
            ->select(
                'refund_items.product_id',
                DB::raw('SUM(refund_items.qty) as qty'),
                DB::raw('SUM(refund_items.amount) as amount'),
            )
            ->groupBy('refund_items.product_id')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->product_id] = [
                'qty' => (float) $row->qty,
                'amount' => (float) $row->amount,
            ];
        }

        return $map;
    }

    private function cogs(int $tenantId, ?string $dateFrom, ?string $dateTo, ?int $warehouseId): float
    {
        return round((float) StockLotDeduction::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->when($dateFrom, fn ($q) => $q->where('deducted_at', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->where('deducted_at', '<=', $dateTo))
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->selectRaw('COALESCE(SUM(total_cost - COALESCE(refunded_qty, 0) * unit_cost), 0) as net_cogs')
            ->value('net_cogs'), 2);
    }

    private function cogsForProduct(
        int $tenantId,
        int $productId,
        ?string $dateFrom,
        ?string $dateTo,
        ?int $warehouseId,
    ): float {
        return round((float) StockLotDeduction::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('product_id', $productId)
            ->when($dateFrom, fn ($q) => $q->where('deducted_at', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->where('deducted_at', '<=', $dateTo))
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->selectRaw('COALESCE(SUM(total_cost - COALESCE(refunded_qty, 0) * unit_cost), 0) as net_cogs')
            ->value('net_cogs'), 2);
    }

    private function soldOrdersQuery(
        int $tenantId,
        ?string $dateFrom,
        ?string $dateTo,
        ?int $warehouseId,
    ): Builder {
        return Order::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where(function ($q) {
                $this->applySoldOrderConstraints($q);
            })
            ->when($dateFrom, fn ($q) => $q->where('created_at', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->where('created_at', '<=', $dateTo))
            ->when($warehouseId, function ($q) use ($warehouseId) {
                $q->where(function ($inner) use ($warehouseId) {
                    $inner->whereExists(function ($sq) use ($warehouseId) {
                        $sq->selectRaw('1')
                            ->from('stock_lot_deductions')
                            ->whereColumn('stock_lot_deductions.order_id', 'orders.id')
                            ->where('stock_lot_deductions.warehouse_id', $warehouseId);
                    })->orWhereExists(function ($sq) use ($warehouseId) {
                        $sq->selectRaw('1')
                            ->from('order_items')
                            ->whereColumn('order_items.order_id', 'orders.id')
                            ->whereRaw("(order_items.snapshot->>'warehouse_id')::int = ?", [$warehouseId]);
                    });
                });
            });
    }

    /**
     * Оплаченные продажи + legacy CRM completed без payment_status.
     */
    private function applySoldOrderConstraints($query, string $table = 'orders'): void
    {
        $legacyStatuses = [
            OrderStatusEnum::COMPLETED->value,
            OrderStatusEnum::COMPLETED_WITH_OVERDRAFT->value,
            OrderStatusEnum::CLOSED->value,
            OrderStatusEnum::ISSUED->value,
            OrderStatusEnum::PARTIALLY_REFUNDED->value,
            OrderStatusEnum::REFUNDED->value,
        ];

        $query->whereNotIn("{$table}.status", [
            OrderStatusEnum::CANCELLED->value,
            OrderStatusEnum::DRAFT->value,
            OrderStatusEnum::PENDING->value,
        ])->where(function ($q) use ($table, $legacyStatuses) {
            $q->whereIn("{$table}.payment_status", [
                PaymentStatusEnum::PAID->value,
                PaymentStatusEnum::PARTIAL->value,
            ])->orWhereIn("{$table}.status", $legacyStatuses);
        });
    }

    private function currentInventoryValue(int $tenantId, ?int $warehouseId): float
    {
        return (float) StockBatch::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('is_overdraft', false)
            ->where('remaining_qty', '>', 0)
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->select(DB::raw('COALESCE(SUM(remaining_qty * cost_price), 0) as val'))
            ->value('val');
    }

    /**
     * XYZ: коэффициент вариации спроса по месяцам внутри диапазона.
     *
     * @return array<int, array{xyz: string, cv: float|null}>
     */
    private function xyzByMonthlyDemand(
        int $tenantId,
        ?string $dateFrom,
        ?string $dateTo,
        ?int $warehouseId,
    ): array {
        $from = $dateFrom ?: now()->subYear()->toDateString();
        $to = $dateTo ?: now()->toDateString();

        $rows = StockLotDeduction::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('deducted_at', '>=', $from)
            ->where('deducted_at', '<=', $to)
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->select(
                'product_id',
                DB::raw("TO_CHAR(deducted_at, 'YYYY-MM') as month"),
                DB::raw('SUM(quantity - COALESCE(refunded_qty, 0)) as qty'),
            )
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
                $result[$pid] = ['xyz' => 'Z', 'cv' => null];
                continue;
            }
            $mean = array_sum($demands) / $n;
            $variance = 0.0;
            foreach ($demands as $d) {
                $variance += ($d - $mean) ** 2;
            }
            $std = sqrt($variance / $n);
            $cv = $mean > 0 ? ($std / $mean) * 100 : 0.0;

            if ($cv < 10) {
                $xyz = 'X';
            } elseif ($cv <= 25) {
                $xyz = 'Y';
            } else {
                $xyz = 'Z';
            }

            $result[$pid] = ['xyz' => $xyz, 'cv' => round($cv, 2)];
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
