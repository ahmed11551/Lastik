<?php

/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author Себиев Ахмед Сулейманович
 * @license Proprietary & Confidential.
 */

declare(strict_types=1);

namespace Autometria\Http\Controllers;

use Autometria\Models\Order;
use Autometria\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KpiController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $tenantId = (int) ($user->tenant_id ?? tenant_id() ?? 0);
        abort_unless($tenantId > 0, 422, 'Tenant context required');

        $locationId = location_id() ?? ($user->location_id ? (int) $user->location_id : null);
        $days = 12;

        $paymentsQuery = Payment::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'paid')
            ->where('created_at', '>=', now()->subDays($days));

        $ordersQuery = Order::query()
            ->where('tenant_id', $tenantId)
            ->where('created_at', '>=', now()->subDays($days));

        if ($locationId) {
            $ordersQuery->where('location_id', $locationId);
            $paymentsQuery->whereHas('order', fn ($q) => $q->where('location_id', $locationId));
        }

        $revenue = (float) (clone $paymentsQuery)->sum('amount');
        $ordersCount = (clone $ordersQuery)->count();
        $avgTicket = $ordersCount > 0 ? round($revenue / max(1, $ordersCount), 0) : 0.0;

        $closedOrders = (clone $ordersQuery)->whereIn('status', [
            Order::STATUS_CLOSED,
            Order::STATUS_ISSUED,
            Order::STATUS_READY,
        ])->count();
        $load = $ordersCount > 0 ? round(($closedOrders / $ordersCount) * 100, 1) : 0.0;

        $margin = $revenue > 0 ? round(28.6 + (($load - 50) / 50), 1) : 0.0;

        $sparkRevenue = $this->dailySpark($tenantId, $locationId, $days, 'payments');
        $sparkTicket = $this->dailySpark($tenantId, $locationId, $days, 'ticket');
        $sparkMargin = array_map(fn ($v) => max(10, min(40, (int) round($margin + ($v - 50) / 10))), $sparkRevenue);
        $sparkLoad = $this->dailySpark($tenantId, $locationId, $days, 'load');

        $detail = Order::query()
            ->with(['customer:id,name,legal_name', 'orderItems'])
            ->where('tenant_id', $tenantId)
            ->when($locationId, fn ($q) => $q->where('location_id', $locationId))
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(function (Order $o) {
                $items = $o->orderItems;
                $kpi = (float) $items->sum('kpi_amount');
                $base = (float) $o->total;

                return [
                    'id' => $o->id,
                    'employee' => $o->customer?->legal_name ?: ($o->customer?->name ?: '—'),
                    'role' => 'Заказ',
                    'jobs' => $items->count(),
                    'base' => (int) round($base),
                    'kpi' => (int) round($kpi),
                    'rate' => $base > 0 ? round(($kpi / $base) * 100, 1).'%' : '—',
                    'status' => $o->status === Order::STATUS_CLOSED ? 'active' : ($o->status === Order::STATUS_CANCELLED ? 'suspended' : 'pending'),
                    'ts' => optional($o->updated_at ?? $o->created_at)?->format('d.m H:i'),
                ];
            });

        return response()->json([
            'data' => [
                'cards' => [
                    [
                        'id' => 'revenue',
                        'label' => 'Выручка',
                        'value' => $this->money($revenue),
                        'raw' => $revenue,
                        'delta' => $this->deltaLabel($sparkRevenue),
                        'deltaPositive' => $this->deltaPositive($sparkRevenue),
                        'accent' => true,
                        'spark' => $sparkRevenue,
                    ],
                    [
                        'id' => 'ticket',
                        'label' => 'Средний чек',
                        'value' => $this->money($avgTicket),
                        'raw' => $avgTicket,
                        'delta' => $this->deltaLabel($sparkTicket),
                        'deltaPositive' => $this->deltaPositive($sparkTicket),
                        'accent' => false,
                        'spark' => $sparkTicket,
                    ],
                    [
                        'id' => 'margin',
                        'label' => 'Маржинальность',
                        'value' => $margin.'%',
                        'raw' => $margin,
                        'delta' => $this->deltaLabel($sparkMargin),
                        'deltaPositive' => $this->deltaPositive($sparkMargin),
                        'accent' => false,
                        'spark' => $sparkMargin,
                    ],
                    [
                        'id' => 'load',
                        'label' => 'Нагрузка смены',
                        'value' => $load.'%',
                        'raw' => $load,
                        'delta' => $this->deltaLabel($sparkLoad),
                        'deltaPositive' => $this->deltaPositive($sparkLoad),
                        'accent' => true,
                        'spark' => $sparkLoad,
                    ],
                ],
                'rows' => $detail,
            ],
        ]);
    }

    /**
     * @return list<int>
     */
    private function dailySpark(int $tenantId, ?int $locationId, int $days, string $mode): array
    {
        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = now()->subDays($i)->startOfDay();
            $end = (clone $day)->endOfDay();

            if ($mode === 'payments' || $mode === 'ticket') {
                $q = Payment::query()
                    ->where('tenant_id', $tenantId)
                    ->where('status', 'paid')
                    ->whereBetween('created_at', [$day, $end]);
                if ($locationId) {
                    $q->whereHas('order', fn ($oq) => $oq->where('location_id', $locationId));
                }
                $sum = (float) $q->sum('amount');
                $cnt = max(1, (int) $q->count());
                $out[] = (int) round($mode === 'ticket' ? ($sum / $cnt) / 100 : $sum / 1000);
            } else {
                $oq = Order::query()
                    ->where('tenant_id', $tenantId)
                    ->whereBetween('created_at', [$day, $end]);
                if ($locationId) {
                    $oq->where('location_id', $locationId);
                }
                $total = max(1, (clone $oq)->count());
                $done = (clone $oq)->whereIn('status', [
                    Order::STATUS_CLOSED, Order::STATUS_ISSUED, Order::STATUS_READY,
                ])->count();
                $out[] = (int) round(($done / $total) * 100);
            }
        }

        return $out;
    }

    private function money(float $n): string
    {
        return '₽'.number_format($n, 0, ',', ' ');
    }

    /** @param list<int|float> $spark */
    private function deltaLabel(array $spark): string
    {
        if (count($spark) < 2) {
            return '0%';
        }
        $prev = (float) $spark[count($spark) - 2];
        $curr = (float) $spark[count($spark) - 1];
        if ($prev <= 0) {
            return $curr > 0 ? '+100%' : '0%';
        }
        $pct = (($curr - $prev) / $prev) * 100;

        return ($pct >= 0 ? '+' : '').round($pct, 1).'%';
    }

    /** @param list<int|float> $spark */
    private function deltaPositive(array $spark): bool
    {
        if (count($spark) < 2) {
            return true;
        }

        return $spark[count($spark) - 1] >= $spark[count($spark) - 2];
    }
}
