<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Services;

use Autometria\Enums\StockReservationStatusEnum;
use Autometria\Exceptions\Domain\InsufficientStockException;
use Autometria\Models\Branch;
use Autometria\Models\Price;
use Autometria\Models\ProductService;
use Autometria\Models\Stock;
use Autometria\Models\StockReservation;
use Autometria\Models\Warehouse;
use Autometria\Models\WarehouseProductPrice;
use Autometria\Support\AuditLog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Block 4.4 — филиалы, ценовые матрицы складов, межскладские резервы.
 */
final class BranchWarehouseService
{
    /**
     * Каскад: warehouse_product_prices → prices.retail/base → product.base_price.
     */
    public function resolveProductPrice(int $tenantId, int $productId, ?int $warehouseId = null): float
    {
        if ($warehouseId !== null && $warehouseId > 0) {
            $override = WarehouseProductPrice::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('warehouse_id', $warehouseId)
                ->where('product_id', $productId)
                ->value('price');

            if ($override !== null) {
                return round((float) $override, 2);
            }
        }

        $base = Price::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('product_id', $productId)
            ->where(function ($q) {
                $q->where('type', 'retail')->orWhere('type', 'base')->orWhereNull('type');
            })
            ->orderByRaw("CASE WHEN type = 'retail' THEN 0 WHEN type = 'base' THEN 1 ELSE 2 END")
            ->value('price');

        if ($base !== null) {
            return round((float) $base, 2);
        }

        $productPrice = ProductService::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereKey($productId)
            ->value('base_price');

        return round((float) ($productPrice ?? 0), 2);
    }

    /**
     * Временный резерв: атомарно увеличивает Stock.reserved / уменьшает available.
     */
    public function reserveStock(
        int $tenantId,
        int $warehouseId,
        int $productId,
        float $qty,
        int $ttlMinutes = 30,
        ?int $createdBy = null,
        ?string $reason = null,
    ): StockReservation {
        if ($qty <= 0) {
            throw new InvalidArgumentException('Reserve qty must be positive');
        }
        if ($ttlMinutes <= 0) {
            throw new InvalidArgumentException('TTL must be positive');
        }

        $this->assertWarehouse($tenantId, $warehouseId);

        return DB::transaction(function () use (
            $tenantId, $warehouseId, $productId, $qty, $ttlMinutes, $createdBy, $reason
        ): StockReservation {
            $this->releaseExpiredReservations($tenantId, $warehouseId, $productId);

            $stock = Stock::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('warehouse_id', $warehouseId)
                ->where('product_id', $productId)
                ->lockForUpdate()
                ->first();

            if ($stock === null) {
                throw new InsufficientStockException('available_less_than_qty');
            }

            if ((float) $stock->available + 0.0001 < $qty) {
                throw new InsufficientStockException('available_less_than_qty');
            }

            $stock->reserved = round((float) $stock->reserved + $qty, 3);
            $stock->available = round((float) $stock->actual - (float) $stock->reserved, 3);
            $stock->save();

            $reservation = StockReservation::query()->withoutGlobalScopes()->forceCreate([
                'tenant_id' => $tenantId,
                'warehouse_id' => $warehouseId,
                'product_id' => $productId,
                'quantity' => round($qty, 3),
                'reserved_until' => now()->addMinutes($ttlMinutes),
                'status' => StockReservationStatusEnum::ACTIVE->value,
                'created_by' => $createdBy,
                'reason' => $reason,
            ]);

            AuditLog::write(
                $tenantId,
                $createdBy ?? auth()->id(),
                'stock.reservation.created',
                StockReservation::class,
                (int) $reservation->id,
                [],
                ['warehouse_id' => $warehouseId, 'product_id' => $productId, 'qty' => $qty],
            );

            return $reservation;
        });
    }

    /**
     * Снимает просроченные ACTIVE-резервы и возвращает кол-во снятых.
     */
    public function releaseExpiredReservations(
        int $tenantId,
        ?int $warehouseId = null,
        ?int $productId = null,
    ): int {
        return (int) DB::transaction(function () use ($tenantId, $warehouseId, $productId): int {
            $query = StockReservation::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('status', StockReservationStatusEnum::ACTIVE->value)
                ->where('reserved_until', '<', now())
                ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
                ->when($productId, fn ($q) => $q->where('product_id', $productId))
                ->lockForUpdate()
                ->orderBy('id');

            $released = 0;
            foreach ($query->get() as $reservation) {
                $this->releaseOne($reservation);
                $released++;
            }

            return $released;
        });
    }

    public function fulfillReservation(int $tenantId, int $reservationId): StockReservation
    {
        return DB::transaction(function () use ($tenantId, $reservationId): StockReservation {
            $reservation = StockReservation::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereKey($reservationId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($reservation->status !== StockReservationStatusEnum::ACTIVE->value) {
                throw new InvalidArgumentException('Reservation is not active');
            }

            $stock = Stock::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('warehouse_id', $reservation->warehouse_id)
                ->where('product_id', $reservation->product_id)
                ->lockForUpdate()
                ->firstOrFail();

            $qty = (float) $reservation->quantity;
            $stock->reserved = max(0, round((float) $stock->reserved - $qty, 3));
            $stock->available = round((float) $stock->actual - (float) $stock->reserved, 3);
            $stock->save();

            $reservation->forceFill(['status' => StockReservationStatusEnum::FULFILLED->value])->save();

            return $reservation->fresh() ?? $reservation;
        });
    }

    /**
     * @param  list<array{product_id: int, price: float}>  $rows
     * @return list<WarehouseProductPrice>
     */
    public function upsertWarehousePrices(int $tenantId, int $warehouseId, array $rows): array
    {
        $this->assertWarehouse($tenantId, $warehouseId);

        return DB::transaction(function () use ($tenantId, $warehouseId, $rows): array {
            $saved = [];
            foreach ($rows as $row) {
                $productId = (int) ($row['product_id'] ?? 0);
                $price = round((float) ($row['price'] ?? 0), 2);
                if ($productId <= 0 || $price < 0) {
                    throw new InvalidArgumentException('Invalid warehouse price row');
                }

                $model = WarehouseProductPrice::query()->withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('warehouse_id', $warehouseId)
                    ->where('product_id', $productId)
                    ->first();

                if ($model === null) {
                    $model = WarehouseProductPrice::query()->withoutGlobalScopes()->forceCreate([
                        'tenant_id' => $tenantId,
                        'warehouse_id' => $warehouseId,
                        'product_id' => $productId,
                        'price' => $price,
                    ]);
                } else {
                    $model->forceFill(['price' => $price])->save();
                }
                $saved[] = $model;
            }

            return $saved;
        });
    }

    /**
     * Сводный остаток по сети (складам / филиалам).
     *
     * @return list<array<string, mixed>>
     */
    public function consolidatedStock(int $tenantId, ?int $branchId = null, ?int $productId = null): array
    {
        $query = Stock::query()->withoutGlobalScopes()
            ->where('stocks.tenant_id', $tenantId)
            ->join('warehouses', 'warehouses.id', '=', 'stocks.warehouse_id')
            ->leftJoin('branches', 'branches.id', '=', 'warehouses.branch_id')
            ->when($branchId, fn ($q) => $q->where('warehouses.branch_id', $branchId))
            ->when($productId, fn ($q) => $q->where('stocks.product_id', $productId))
            ->select([
                'stocks.product_id',
                'stocks.warehouse_id',
                'warehouses.name as warehouse_name',
                'warehouses.branch_id',
                'branches.name as branch_name',
                'branches.code as branch_code',
                'stocks.actual',
                'stocks.reserved',
                'stocks.available',
            ])
            ->orderBy('branches.code')
            ->orderBy('warehouses.name');

        return $query->get()->map(fn ($row) => [
            'product_id' => (int) $row->product_id,
            'warehouse_id' => (int) $row->warehouse_id,
            'warehouse_name' => $row->warehouse_name,
            'branch_id' => $row->branch_id ? (int) $row->branch_id : null,
            'branch_name' => $row->branch_name,
            'branch_code' => $row->branch_code,
            'actual' => round((float) $row->actual, 3),
            'reserved' => round((float) $row->reserved, 3),
            'available' => round((float) $row->available, 3),
        ])->all();
    }

    public function createBranch(
        int $tenantId,
        string $name,
        string $code,
        ?string $address,
        ?int $defaultWarehouseId,
        bool $isActive = true,
    ): Branch {
        if ($defaultWarehouseId !== null) {
            $this->assertWarehouse($tenantId, $defaultWarehouseId);
        }

        return Branch::query()->withoutGlobalScopes()->forceCreate([
            'tenant_id' => $tenantId,
            'name' => $name,
            'code' => strtoupper(trim($code)),
            'address' => $address,
            'default_warehouse_id' => $defaultWarehouseId,
            'is_active' => $isActive,
        ]);
    }

    public function updateBranch(int $tenantId, int $branchId, array $data): Branch
    {
        $branch = Branch::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereKey($branchId)
            ->firstOrFail();

        if (isset($data['default_warehouse_id']) && $data['default_warehouse_id'] !== null) {
            $this->assertWarehouse($tenantId, (int) $data['default_warehouse_id']);
        }

        $branch->forceFill([
            'name' => $data['name'] ?? $branch->name,
            'code' => isset($data['code']) ? strtoupper(trim((string) $data['code'])) : $branch->code,
            'address' => array_key_exists('address', $data) ? $data['address'] : $branch->address,
            'default_warehouse_id' => array_key_exists('default_warehouse_id', $data)
                ? $data['default_warehouse_id']
                : $branch->default_warehouse_id,
            'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : $branch->is_active,
        ])->save();

        return $branch->fresh() ?? $branch;
    }

    /**
     * @return Collection<int, Branch>
     */
    public function listBranches(int $tenantId, bool $activeOnly = false): Collection
    {
        return Branch::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->when($activeOnly, fn ($q) => $q->where('is_active', true))
            ->with(['defaultWarehouse', 'warehouses'])
            ->orderBy('code')
            ->get();
    }

    private function releaseOne(StockReservation $reservation): void
    {
        $stock = Stock::query()->withoutGlobalScopes()
            ->where('tenant_id', $reservation->tenant_id)
            ->where('warehouse_id', $reservation->warehouse_id)
            ->where('product_id', $reservation->product_id)
            ->lockForUpdate()
            ->first();

        if ($stock !== null) {
            $qty = (float) $reservation->quantity;
            $stock->reserved = max(0, round((float) $stock->reserved - $qty, 3));
            $stock->available = round((float) $stock->actual - (float) $stock->reserved, 3);
            $stock->save();
        }

        $reservation->forceFill(['status' => StockReservationStatusEnum::RELEASED->value])->save();
    }

    private function assertWarehouse(int $tenantId, int $warehouseId): void
    {
        $ok = Warehouse::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereKey($warehouseId)
            ->exists();

        if (! $ok) {
            throw new InvalidArgumentException('Warehouse not found for tenant');
        }
    }
}
