<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Services\Wms;

use Autometria\Models\StockBatch;
use Autometria\Models\StockBatchCell;
use Autometria\Models\StorageCell;
use Autometria\Models\Warehouse;
use Autometria\Support\AuditLog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * WMS Light — CRUD ячеек, размещение партий, перемещение между ячейками.
 */
final class StorageCellService
{
    /**
     * @return Collection<int, StorageCell>
     */
    public function list(int $tenantId, ?int $warehouseId = null, bool $activeOnly = false): Collection
    {
        $q = StorageCell::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->with('warehouse')
            ->orderBy('code');

        if ($warehouseId !== null) {
            $q->where('warehouse_id', $warehouseId);
        }
        if ($activeOnly) {
            $q->where('is_active', true);
        }

        return $q->get();
    }

    /**
     * @param  array{
     *   warehouse_id: int,
     *   code: string,
     *   zone?: ?string,
     *   rack?: ?string,
     *   shelf?: ?string,
     *   bin?: ?string,
     *   description?: ?string,
     *   is_active?: bool
     * }  $data
     */
    public function create(int $tenantId, array $data, ?int $userId = null): StorageCell
    {
        $warehouseId = (int) $data['warehouse_id'];
        $this->assertWarehouse($tenantId, $warehouseId);

        $code = trim((string) $data['code']);
        if ($code === '') {
            throw new InvalidArgumentException('Cell code is required');
        }

        $cell = StorageCell::query()->withoutGlobalScopes()->forceCreate([
            'tenant_id' => $tenantId,
            'warehouse_id' => $warehouseId,
            'code' => $code,
            'zone' => $data['zone'] ?? null,
            'rack' => $data['rack'] ?? null,
            'shelf' => $data['shelf'] ?? null,
            'bin' => $data['bin'] ?? null,
            'description' => $data['description'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);

        AuditLog::write(
            $tenantId,
            $userId ?? auth()->id(),
            'wms.cell.created',
            StorageCell::class,
            (int) $cell->id,
            [],
            ['code' => $code, 'warehouse_id' => $warehouseId],
        );

        return $cell->load('warehouse');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(int $tenantId, int $cellId, array $data, ?int $userId = null): StorageCell
    {
        $cell = $this->findOrFail($tenantId, $cellId);

        $fill = array_intersect_key($data, array_flip([
            'code', 'zone', 'rack', 'shelf', 'bin', 'description', 'is_active',
        ]));
        if (isset($fill['code'])) {
            $fill['code'] = trim((string) $fill['code']);
            if ($fill['code'] === '') {
                throw new InvalidArgumentException('Cell code is required');
            }
        }

        $cell->forceFill($fill)->save();

        AuditLog::write(
            $tenantId,
            $userId ?? auth()->id(),
            'wms.cell.updated',
            StorageCell::class,
            (int) $cell->id,
            [],
            $fill,
        );

        return $cell->fresh(['warehouse']);
    }

    public function delete(int $tenantId, int $cellId, ?int $userId = null): void
    {
        $cell = $this->findOrFail($tenantId, $cellId);

        $hasQty = StockBatchCell::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('storage_cell_id', $cell->id)
            ->where('quantity', '>', 0)
            ->exists();

        if ($hasQty) {
            throw new InvalidArgumentException('Cannot delete cell with stock placements');
        }

        StockBatchCell::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('storage_cell_id', $cell->id)
            ->delete();

        $cell->delete();

        AuditLog::write(
            $tenantId,
            $userId ?? auth()->id(),
            'wms.cell.deleted',
            StorageCell::class,
            $cellId,
            [],
            [],
        );
    }

    /**
     * Разместить qty партии в ячейку (инкремент или create).
     */
    public function placeBatch(
        int $tenantId,
        int $stockBatchId,
        int $storageCellId,
        float $qty,
        ?int $userId = null,
    ): StockBatchCell {
        if ($qty <= 0) {
            throw new InvalidArgumentException('Placement qty must be positive');
        }

        return DB::transaction(function () use ($tenantId, $stockBatchId, $storageCellId, $qty, $userId): StockBatchCell {
            $batch = StockBatch::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereKey($stockBatchId)
                ->lockForUpdate()
                ->firstOrFail();

            $cell = $this->findOrFail($tenantId, $storageCellId);
            if ((int) $cell->warehouse_id !== (int) $batch->warehouse_id) {
                throw new InvalidArgumentException('Cell warehouse must match batch warehouse');
            }

            $placed = (float) StockBatchCell::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('stock_batch_id', $batch->id)
                ->sum('quantity');

            $available = round((float) $batch->remaining_qty - $placed, 3);
            if ($qty > $available + 0.0001) {
                throw new InvalidArgumentException('Placement qty exceeds unplaced batch remaining');
            }

            $row = StockBatchCell::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('stock_batch_id', $batch->id)
                ->where('storage_cell_id', $cell->id)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                $row = StockBatchCell::query()->withoutGlobalScopes()->forceCreate([
                    'tenant_id' => $tenantId,
                    'stock_batch_id' => $batch->id,
                    'storage_cell_id' => $cell->id,
                    'quantity' => round($qty, 3),
                ]);
            } else {
                $row->quantity = round((float) $row->quantity + $qty, 3);
                $row->save();
            }

            AuditLog::write(
                $tenantId,
                $userId ?? auth()->id(),
                'wms.batch.placed',
                StockBatchCell::class,
                (int) $row->id,
                [],
                [
                    'stock_batch_id' => $batch->id,
                    'storage_cell_id' => $cell->id,
                    'qty' => round($qty, 3),
                ],
            );

            return $row->fresh(['cell', 'stockBatch']);
        });
    }

    /**
     * Переместить qty партии из одной ячейки в другую.
     */
    public function moveBatch(
        int $tenantId,
        int $stockBatchId,
        int $fromCellId,
        int $toCellId,
        float $qty,
        ?int $userId = null,
    ): StockBatchCell {
        if ($qty <= 0) {
            throw new InvalidArgumentException('Move qty must be positive');
        }
        if ($fromCellId === $toCellId) {
            throw new InvalidArgumentException('Source and target cells must differ');
        }

        return DB::transaction(function () use (
            $tenantId, $stockBatchId, $fromCellId, $toCellId, $qty, $userId
        ): StockBatchCell {
            $from = StockBatchCell::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('stock_batch_id', $stockBatchId)
                ->where('storage_cell_id', $fromCellId)
                ->lockForUpdate()
                ->firstOrFail();

            if ((float) $from->quantity + 0.0001 < $qty) {
                throw new InvalidArgumentException('Insufficient qty in source cell');
            }

            $toCell = $this->findOrFail($tenantId, $toCellId);
            $batch = StockBatch::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereKey($stockBatchId)
                ->firstOrFail();

            if ((int) $toCell->warehouse_id !== (int) $batch->warehouse_id) {
                throw new InvalidArgumentException('Target cell warehouse must match batch warehouse');
            }

            $from->quantity = round((float) $from->quantity - $qty, 3);
            if ((float) $from->quantity <= 0.0001) {
                $from->delete();
            } else {
                $from->save();
            }

            $target = StockBatchCell::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('stock_batch_id', $stockBatchId)
                ->where('storage_cell_id', $toCellId)
                ->lockForUpdate()
                ->first();

            if ($target === null) {
                $target = StockBatchCell::query()->withoutGlobalScopes()->forceCreate([
                    'tenant_id' => $tenantId,
                    'stock_batch_id' => $stockBatchId,
                    'storage_cell_id' => $toCellId,
                    'quantity' => round($qty, 3),
                ]);
            } else {
                $target->quantity = round((float) $target->quantity + $qty, 3);
                $target->save();
            }

            AuditLog::write(
                $tenantId,
                $userId ?? auth()->id(),
                'wms.batch.moved',
                StockBatchCell::class,
                (int) $target->id,
                [],
                [
                    'stock_batch_id' => $stockBatchId,
                    'from_cell_id' => $fromCellId,
                    'to_cell_id' => $toCellId,
                    'qty' => round($qty, 3),
                ],
            );

            return $target->fresh(['cell', 'stockBatch']);
        });
    }

    public function findOrFail(int $tenantId, int $cellId): StorageCell
    {
        return StorageCell::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereKey($cellId)
            ->firstOrFail();
    }

    private function assertWarehouse(int $tenantId, int $warehouseId): void
    {
        $ok = Warehouse::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereKey($warehouseId)
            ->exists();

        if (! $ok) {
            throw new InvalidArgumentException('Warehouse not found');
        }
    }
}
