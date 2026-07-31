<?php

declare(strict_types=1);

namespace App\Services\Kpi;

use App\Models\Earning;
use App\Models\KpiRule;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Facades\DB;

class KpiService
{
    public function calculateOrderEarning(Order $order): Earning
    {
        return DB::transaction(function () use ($order) {
            $tenantId = $order->tenant_id;
            $assignedUserId = $order->assigned_seller_id;

            if ($assignedUserId === null) {
                throw new \RuntimeException('Order has no assigned seller for KPI');
            }

            $rule = KpiRule::where('tenant_id', $tenantId)
                ->where('applies_to', 'order')
                ->where('is_active', true)
                ->where(function ($query) {
                    $query->whereNull('valid_to')->orWhere('valid_to', '>', now());
                })
                ->orderByDesc('id')
                ->first();

            $amount = $order->total;
            $percent = $rule !== null ? (float) $rule->percent : 0.0;
            $bonus = $order->total * ($percent / 100);

            if ((float) ($rule?->amount ?? 0) > 0) {
                $bonus = max($bonus, (float) $rule->amount);
            }

            $ruleSnapshot = [
                'id' => $rule?->id,
                'percent' => (string) $percent,
                'amount' => (string) round($bonus, 2),
                'valid_from' => optional($rule)->valid_from?->toDateTimeString(),
                'valid_to' => optional($rule)->valid_to?->toDateTimeString(),
            ];

            $bonus = round($bonus, 2);

            $earning = Earning::create([
                'tenant_id' => $tenantId,
                'order_id' => $order->id,
                'user_id' => $assignedUserId,
                'amount' => $bonus,
                'rule_snapshot' => $ruleSnapshot,
                'source' => Earning::SOURCE_ORDER,
            ]);

            AuditLog::write(
                $tenantId,
                auth()->id(),
                'kpi.order.earned',
                Earning::class,
                $earning->id,
                [
                    'order_id' => $order->id,
                    'amount' => $bonus,
                    'rule_snapshot' => $ruleSnapshot,
                ]
            );

            return $earning;
        });
    }

    public function calculateItemEarning(OrderItem $item): Earning
    {
        $order = $item->order;

        if ($order === null) {
            throw new \RuntimeException('Order item has no order context for KPI');
        }

        $tenantId = $order->tenant_id;
        $userId = $order->assigned_seller_id ?? $order->master_id;

        if ($userId === null) {
            throw new \RuntimeException('Order item has no assignee for KPI');
        }

        $rule = KpiRule::where('tenant_id', $tenantId)
            ->where('applies_to', 'item')
            ->where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('valid_to')->orWhere('valid_to', '>', now());
            })
            ->orderByDesc('id')
            ->first();

        $positionTotal = $item->qty * $item->price;
        $percent = $rule?->percent ?? 0;
        $bonus = $positionTotal * ($percent / 100);

        if ((float) ($rule?->amount ?? 0) > 0) {
            $bonus = max($bonus, (float) $rule->amount);
        }

        $bonus = round($bonus, 2);

        $ruleSnapshot = [
            'id' => $rule?->id,
            'percent' => (string) $percent,
            'amount' => (string) $bonus,
            'position_total' => (string) round($positionTotal, 2),
            'valid_from' => optional($rule)->valid_from?->toDateTimeString(),
            'valid_to' => optional($rule)->valid_to?->toDateTimeString(),
        ];

        $earning = Earning::create([
            'tenant_id' => $tenantId,
            'order_id' => $order->id,
            'user_id' => $userId,
            'amount' => $bonus,
            'rule_snapshot' => $ruleSnapshot,
            'source' => Earning::SOURCE_ITEM,
        ]);

        AuditLog::write(
            $tenantId,
            auth()->id(),
            'kpi.item.earned',
            Earning::class,
            $earning->id,
            [
                'order_id' => $order->id,
                'order_item_id' => $item->id,
                'amount' => $bonus,
                'rule_snapshot' => $ruleSnapshot,
            ]
        );

        return $earning;
    }

    public function snapshotRule(KpiRule $rule): array
    {
        return [
            'id' => $rule->id,
            'applies_to' => $rule->applies_to,
            'percent' => (string) $rule->percent,
            'amount' => (string) $rule->amount,
            'is_active' => (bool) $rule->is_active,
            'valid_from' => optional($rule->valid_from)?->toDateTimeString(),
            'valid_to' => optional($rule->valid_to)?->toDateTimeString(),
        ];
    }
}
