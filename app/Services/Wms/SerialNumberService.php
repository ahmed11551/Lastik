<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Services\Wms;

use Autometria\Models\ProductService;
use Autometria\Models\SerialNumber;
use Autometria\Models\StockBatch;
use Autometria\Support\AuditLog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * WMS Light — серийный учёт (приёмка / продажа / списание).
 */
final class SerialNumberService
{
    /**
     * @return Collection<int, SerialNumber>
     */
    public function list(
        int $tenantId,
        ?int $productId = null,
        ?string $status = null,
        ?int $stockBatchId = null,
    ): Collection {
        $q = SerialNumber::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->with(['product', 'stockBatch'])
            ->orderByDesc('id');

        if ($productId !== null) {
            $q->where('product_id', $productId);
        }
        if ($status !== null && $status !== '') {
            $q->where('status', $status);
        }
        if ($stockBatchId !== null) {
            $q->where('stock_batch_id', $stockBatchId);
        }

        return $q->limit(500)->get();
    }

    /**
     * Зарегистрировать серийники на партию (приёмка).
     *
     * @param  list<string>  $serials
     * @return list<SerialNumber>
     */
    public function receive(
        int $tenantId,
        int $productId,
        int $stockBatchId,
        array $serials,
        ?int $warehouseId = null,
        ?int $userId = null,
    ): array {
        $serials = array_values(array_unique(array_filter(array_map(
            static fn ($s) => trim((string) $s),
            $serials,
        ), static fn (string $s) => $s !== '')));

        if ($serials === []) {
            throw new InvalidArgumentException('At least one serial is required');
        }

        return DB::transaction(function () use (
            $tenantId, $productId, $stockBatchId, $serials, $warehouseId, $userId
        ): array {
            $product = ProductService::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereKey($productId)
                ->firstOrFail();

            $batch = StockBatch::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereKey($stockBatchId)
                ->firstOrFail();

            if ((int) $batch->product_id !== (int) $product->id) {
                throw new InvalidArgumentException('Batch product mismatch');
            }

            $wh = $warehouseId ?? (int) $batch->warehouse_id;
            $created = [];

            foreach ($serials as $serial) {
                $exists = SerialNumber::query()->withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('serial', $serial)
                    ->exists();
                if ($exists) {
                    throw new InvalidArgumentException("Serial already exists: {$serial}");
                }

                $row = SerialNumber::query()->withoutGlobalScopes()->forceCreate([
                    'tenant_id' => $tenantId,
                    'product_id' => $product->id,
                    'stock_batch_id' => $batch->id,
                    'warehouse_id' => $wh > 0 ? $wh : null,
                    'serial' => $serial,
                    'status' => SerialNumber::STATUS_IN_STOCK,
                ]);
                $created[] = $row;
            }

            AuditLog::write(
                $tenantId,
                $userId ?? auth()->id(),
                'wms.serial.received',
                StockBatch::class,
                (int) $batch->id,
                [],
                [
                    'product_id' => $product->id,
                    'count' => count($created),
                    'serials' => $serials,
                ],
            );

            return $created;
        });
    }

    /**
     * @param  list<string>|list<int>  $serialsOrIds  serial strings or ids
     */
    public function markSold(int $tenantId, array $serialsOrIds, ?int $userId = null): int
    {
        return $this->transition($tenantId, $serialsOrIds, SerialNumber::STATUS_SOLD, 'wms.serial.sold', $userId);
    }

    /**
     * @param  list<string>|list<int>  $serialsOrIds
     */
    public function markWrittenOff(int $tenantId, array $serialsOrIds, ?int $userId = null): int
    {
        return $this->transition($tenantId, $serialsOrIds, SerialNumber::STATUS_WRITTEN_OFF, 'wms.serial.written_off', $userId);
    }

    /**
     * @param  list<string>|list<int>  $serialsOrIds
     */
    private function transition(
        int $tenantId,
        array $serialsOrIds,
        string $status,
        string $auditAction,
        ?int $userId,
    ): int {
        $ids = [];
        $serials = [];
        foreach ($serialsOrIds as $v) {
            if (is_int($v) || (is_string($v) && ctype_digit($v))) {
                $ids[] = (int) $v;
            } else {
                $serials[] = trim((string) $v);
            }
        }

        return DB::transaction(function () use ($tenantId, $ids, $serials, $status, $auditAction, $userId): int {
            $q = SerialNumber::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('status', SerialNumber::STATUS_IN_STOCK)
                ->lockForUpdate();

            if ($ids !== [] && $serials !== []) {
                $q->where(function ($inner) use ($ids, $serials): void {
                    $inner->whereIn('id', $ids)->orWhereIn('serial', $serials);
                });
            } elseif ($ids !== []) {
                $q->whereIn('id', $ids);
            } elseif ($serials !== []) {
                $q->whereIn('serial', $serials);
            } else {
                return 0;
            }

            $rows = $q->get();
            foreach ($rows as $row) {
                $row->status = $status;
                $row->save();
            }

            if ($rows->isNotEmpty()) {
                AuditLog::write(
                    $tenantId,
                    $userId ?? auth()->id(),
                    $auditAction,
                    SerialNumber::class,
                    (int) $rows->first()->id,
                    [],
                    ['count' => $rows->count(), 'status' => $status],
                );
            }

            return $rows->count();
        });
    }
}
