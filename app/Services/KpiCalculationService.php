<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\OrderItem;
use Illuminate\Support\Facades\DB;

class KpiCalculationService
{
    public function attachWorker(int $tenantId, OrderItem $orderItem, int $workerId, float $commissionRate): void
    {
        $earned = round($orderItem->summary() * ($commissionRate / 100), 2);

        DB::table('order_item_workers')->insert([
            'tenant_id' => $tenantId,
            'order_item_id' => $orderItem->id,
            'worker_id' => $workerId,
            'commission_rate' => $commissionRate,
            'earned_amount' => $earned,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
