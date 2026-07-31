<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\Domain\InsufficientStockException;
use App\Models\Stock;
use App\Services\Reservation\InvalidReservationException;
use Illuminate\Support\Facades\DB;

/**
 * Списание / корректировка физических остатков (actual/reserved) под блокировкой строки.
 */
final class OrderFulfillmentService
{
    /**
     * Выдача со склада: уменьшает actual и reserved атомарно.
     *
     * @return Stock locked & refreshed
     */
    public function deductIssuedQty(int $stockId, int $tenantId, float $qty): Stock
    {
        if ($qty <= 0) {
            throw new InvalidReservationException('Fulfillment qty must be positive');
        }

        return DB::transaction(function () use ($stockId, $tenantId, $qty): Stock {
            $stock = Stock::query()->withoutGlobalScopes()
                ->whereKey($stockId)
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->firstOrFail();

            $newActual = (float) $stock->actual - $qty;
            $newReserved = (float) $stock->reserved - $qty;

            if ($newActual < -0.0001 || $newReserved < -0.0001) {
                throw new InsufficientStockException('Cannot deduct more than on-hand / reserved');
            }

            $stock->actual = max(0, $newActual);
            $stock->reserved = max(0, $newReserved);
            $stock->available = (float) $stock->actual - (float) $stock->reserved;
            $stock->save();

            return $stock->fresh() ?? $stock;
        });
    }
}
