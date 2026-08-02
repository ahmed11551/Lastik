<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Services;

use Autometria\Enums\InventoryDocumentStatusEnum;
use Autometria\Enums\InventoryDocumentTypeEnum;
use Autometria\Exceptions\Domain\InsufficientStockException;
use Autometria\Models\InventoryDocument;
use Autometria\Models\InventoryDocumentItem;
use Autometria\Models\Stock;
use Autometria\Models\StockBatch;
use Autometria\Models\Warehouse;
use Autometria\Support\AuditLog;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Block 4.2 — складские документы: приход / списание / перемещение / инвентаризация.
 */
final class StockDocumentService
{
    public function __construct(
        private readonly StockBatchService $batches,
    ) {}

    /**
     * @param  list<array{product_id: int, qty?: float, quantity?: float, price?: float, cost_price?: float, reason?: ?string, sku?: ?string, name?: ?string}>  $items
     */
    public function createDraft(
        int $tenantId,
        string $type,
        int $warehouseId,
        ?int $targetWarehouseId,
        array $items,
        int $createdBy,
    ): InventoryDocument {
        $canonical = InventoryDocumentTypeEnum::normalize($type);

        $this->assertWarehouseBelongsToTenant($tenantId, $warehouseId);
        if ($canonical === InventoryDocumentTypeEnum::TRANSFER) {
            if ($targetWarehouseId === null) {
                throw new InvalidArgumentException('target_warehouse_id is required for TRANSFER');
            }
            if ($targetWarehouseId === $warehouseId) {
                throw new InvalidArgumentException('Source and destination warehouses must differ');
            }
            $this->assertWarehouseBelongsToTenant($tenantId, $targetWarehouseId);
        }

        if ($items === []) {
            throw new InvalidArgumentException('Document items are required');
        }

        return DB::transaction(function () use (
            $tenantId, $canonical, $warehouseId, $targetWarehouseId, $items, $createdBy
        ): InventoryDocument {
            $doc = InventoryDocument::query()->withoutGlobalScopes()->forceCreate([
                'tenant_id' => $tenantId,
                'warehouse_id' => $warehouseId,
                'target_warehouse_id' => $canonical === InventoryDocumentTypeEnum::TRANSFER
                    ? $targetWarehouseId
                    : null,
                'type' => $canonical->value,
                'status' => InventoryDocumentStatusEnum::DRAFT->value,
                'number' => $this->nextNumber($tenantId, $canonical),
                'created_by' => $createdBy,
            ]);

            foreach ($items as $row) {
                $productId = (int) ($row['product_id'] ?? 0);
                $qty = (float) ($row['qty'] ?? $row['quantity'] ?? 0);
                if ($productId <= 0 || $qty <= 0) {
                    throw new InvalidArgumentException('Each item requires product_id and positive qty');
                }
                InventoryDocumentItem::query()->withoutGlobalScopes()->forceCreate([
                    'tenant_id' => $tenantId,
                    'document_id' => $doc->id,
                    'product_id' => $productId,
                    'quantity' => round($qty, 3),
                    'cost_price' => round((float) ($row['price'] ?? $row['cost_price'] ?? 0), 2),
                    'reason' => isset($row['reason']) ? (string) $row['reason'] : null,
                    'sku' => isset($row['sku']) ? (string) $row['sku'] : null,
                    'name' => isset($row['name']) ? (string) $row['name'] : null,
                ]);
            }

            AuditLog::write(
                $tenantId,
                $createdBy,
                'inventory.document.created',
                InventoryDocument::class,
                (int) $doc->id,
                [],
                ['type' => $canonical->value, 'number' => $doc->number],
            );

            return $doc->fresh(['items']) ?? $doc;
        });
    }

    public function post(int $tenantId, int $documentId, int $postedBy): InventoryDocument
    {
        return DB::transaction(function () use ($tenantId, $documentId, $postedBy): InventoryDocument {
            $doc = InventoryDocument::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereKey($documentId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($doc->status === InventoryDocumentStatusEnum::POSTED->value) {
                throw new RuntimeException('Document already posted');
            }
            if ($doc->status === InventoryDocumentStatusEnum::CANCELLED->value) {
                throw new RuntimeException('Cancelled document cannot be posted');
            }

            $this->assertWarehouseBelongsToTenant($tenantId, (int) $doc->warehouse_id);
            if ($doc->target_warehouse_id) {
                $this->assertWarehouseBelongsToTenant($tenantId, (int) $doc->target_warehouse_id);
            }

            $items = InventoryDocumentItem::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('document_id', $doc->id)
                ->lockForUpdate()
                ->get();

            if ($items->isEmpty()) {
                throw new InvalidArgumentException('Cannot post empty document');
            }

            $type = InventoryDocumentTypeEnum::normalize((string) $doc->type);

            match ($type) {
                InventoryDocumentTypeEnum::RECEIPT => $this->postIncoming($tenantId, $doc, $items, $postedBy),
                InventoryDocumentTypeEnum::WRITE_OFF => $this->postWriteOff($tenantId, $doc, $items, $postedBy),
                InventoryDocumentTypeEnum::TRANSFER => $this->postTransfer($tenantId, $doc, $items, $postedBy),
                InventoryDocumentTypeEnum::INVENTORY => $this->postAudit($tenantId, $doc, $items, $postedBy),
                default => throw new InvalidArgumentException('Unsupported document type'),
            };

            $doc->forceFill([
                'status' => InventoryDocumentStatusEnum::POSTED->value,
                'posted_at' => now(),
            ])->save();

            AuditLog::write(
                $tenantId,
                $postedBy,
                'inventory.document.posted',
                InventoryDocument::class,
                (int) $doc->id,
                [],
                ['type' => $doc->type, 'number' => $doc->number],
            );

            return $doc->fresh(['items']) ?? $doc;
        });
    }

    /**
     * @param  \Illuminate\Support\Collection<int, InventoryDocumentItem>  $items
     */
    private function postIncoming(int $tenantId, InventoryDocument $doc, $items, int $userId): void
    {
        foreach ($items as $item) {
            $this->batches->ingress(
                $tenantId,
                (int) $doc->warehouse_id,
                (int) $item->product_id,
                (float) $item->quantity,
                (float) $item->cost_price,
                'DOC-'.$doc->number.'-'.$item->id,
                $userId,
            );
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<int, InventoryDocumentItem>  $items
     */
    private function postWriteOff(int $tenantId, InventoryDocument $doc, $items, int $userId): void
    {
        foreach ($items as $item) {
            $this->batches->writeOff(
                $tenantId,
                (int) $doc->warehouse_id,
                (int) $item->product_id,
                (float) $item->quantity,
                $userId,
                null,
                null,
                false,
            );
        }
    }

    /**
     * Атомарный перенос FIFO-партий: списание со склада-источника + ingress с той же себестоимостью.
     *
     * @param  \Illuminate\Support\Collection<int, InventoryDocumentItem>  $items
     */
    private function postTransfer(int $tenantId, InventoryDocument $doc, $items, int $userId): void
    {
        $fromId = (int) $doc->warehouse_id;
        $toId = (int) $doc->target_warehouse_id;
        if ($toId <= 0) {
            throw new InvalidArgumentException('target_warehouse_id missing');
        }

        foreach ($items as $item) {
            $this->transferLotsFifo(
                $tenantId,
                $fromId,
                $toId,
                (int) $item->product_id,
                (float) $item->quantity,
                $userId,
                (string) $doc->number,
            );
        }
    }

    /**
     * Инвентаризация: quantity = фактический остаток; разница → ingress / writeOff / no-op.
     *
     * @param  \Illuminate\Support\Collection<int, InventoryDocumentItem>  $items
     */
    private function postAudit(int $tenantId, InventoryDocument $doc, $items, int $userId): void
    {
        $warehouseId = (int) $doc->warehouse_id;

        foreach ($items as $item) {
            $productId = (int) $item->product_id;
            $counted = round((float) $item->quantity, 3);

            $stock = Stock::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('warehouse_id', $warehouseId)
                ->where('product_id', $productId)
                ->lockForUpdate()
                ->first();

            $system = $stock ? round((float) $stock->actual, 3) : 0.0;
            $diff = round($counted - $system, 3);

            if (abs($diff) < 0.0001) {
                continue;
            }

            if ($diff > 0) {
                $cost = (float) $item->cost_price;
                if ($cost <= 0) {
                    $cost = (float) (StockBatch::query()->withoutGlobalScopes()
                        ->where('tenant_id', $tenantId)
                        ->where('warehouse_id', $warehouseId)
                        ->where('product_id', $productId)
                        ->where('remaining_qty', '>', 0)
                        ->orderByDesc('received_at')
                        ->value('cost_price') ?? 0);
                }
                $this->batches->ingress(
                    $tenantId,
                    $warehouseId,
                    $productId,
                    $diff,
                    $cost,
                    'AUDIT-'.$doc->number.'-'.$item->id,
                    $userId,
                );
            } else {
                $this->batches->writeOff(
                    $tenantId,
                    $warehouseId,
                    $productId,
                    abs($diff),
                    $userId,
                    null,
                    null,
                    false,
                );
            }
        }
    }

    private function transferLotsFifo(
        int $tenantId,
        int $fromWarehouseId,
        int $toWarehouseId,
        int $productId,
        float $qty,
        int $userId,
        string $docNumber,
    ): void {
        $fromStock = $this->lockOrFailStock($tenantId, $fromWarehouseId, $productId);
        if ((float) $fromStock->available + 0.0001 < $qty) {
            throw new InsufficientStockException('available_less_than_qty');
        }

        $batches = StockBatch::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('warehouse_id', $fromWarehouseId)
            ->where('product_id', $productId)
            ->where('remaining_qty', '>', 0)
            ->where(function ($q): void {
                $q->where('is_overdraft', false)->orWhereNull('is_overdraft');
            })
            ->orderBy('received_at', 'asc')
            ->orderBy('id', 'asc')
            ->lockForUpdate()
            ->get();

        $remaining = round($qty, 3);
        $moved = 0.0;

        foreach ($batches as $batch) {
            if ($remaining <= 0.0001) {
                break;
            }
            $available = (float) $batch->remaining_qty;
            $take = min($available, $remaining);
            $batch->remaining_qty = round($available - $take, 3);
            $batch->save();

            StockBatch::query()->withoutGlobalScopes()->forceCreate([
                'tenant_id' => $tenantId,
                'warehouse_id' => $toWarehouseId,
                'product_id' => $productId,
                'batch_number' => 'TR-'.$docNumber.'-'.$batch->id,
                'qty' => round($take, 3),
                'remaining_qty' => round($take, 3),
                'cost_price' => round((float) $batch->cost_price, 2),
                'received_at' => $batch->received_at ?? now(),
                'is_overdraft' => false,
            ]);

            $moved += $take;
            $remaining = round($remaining - $take, 3);
        }

        if ($remaining > 0.0001) {
            throw new InsufficientStockException('batch_coverage_gap');
        }

        $fromStock->actual = round((float) $fromStock->actual - $moved, 3);
        $fromStock->available = round((float) $fromStock->actual - (float) $fromStock->reserved, 3);
        $fromStock->save();

        $toStock = Stock::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('warehouse_id', $toWarehouseId)
            ->where('product_id', $productId)
            ->lockForUpdate()
            ->first();

        if ($toStock === null) {
            $toStock = Stock::query()->withoutGlobalScopes()->forceCreate([
                'tenant_id' => $tenantId,
                'warehouse_id' => $toWarehouseId,
                'product_id' => $productId,
                'actual' => 0,
                'reserved' => 0,
                'available' => 0,
            ]);
            $toStock = Stock::query()->withoutGlobalScopes()->whereKey($toStock->id)->lockForUpdate()->firstOrFail();
        }

        $toStock->actual = round((float) $toStock->actual + $moved, 3);
        $toStock->available = round((float) $toStock->actual - (float) $toStock->reserved, 3);
        $toStock->save();

        AuditLog::write(
            $tenantId,
            $userId,
            'inventory.document.transfer_lots',
            Stock::class,
            (int) $fromStock->id,
            [],
            [
                'from_warehouse_id' => $fromWarehouseId,
                'to_warehouse_id' => $toWarehouseId,
                'product_id' => $productId,
                'qty' => $moved,
            ],
        );
    }

    private function lockOrFailStock(int $tenantId, int $warehouseId, int $productId): Stock
    {
        $stock = Stock::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->lockForUpdate()
            ->first();

        if ($stock === null) {
            throw new InsufficientStockException('available_less_than_qty');
        }

        return $stock;
    }

    private function assertWarehouseBelongsToTenant(int $tenantId, int $warehouseId): void
    {
        $ok = Warehouse::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereKey($warehouseId)
            ->exists();

        if (! $ok) {
            throw new InvalidArgumentException('Warehouse not found for tenant');
        }
    }

    private function nextNumber(int $tenantId, InventoryDocumentTypeEnum $type): string
    {
        $prefix = match ($type) {
            InventoryDocumentTypeEnum::RECEIPT => 'IN',
            InventoryDocumentTypeEnum::WRITE_OFF => 'WO',
            InventoryDocumentTypeEnum::TRANSFER => 'TR',
            InventoryDocumentTypeEnum::INVENTORY => 'AU',
            default => 'DOC',
        };

        $seq = InventoryDocument::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('type', $type->value)
            ->count() + 1;

        return sprintf('%s-%s-%04d', $prefix, now()->format('ymd'), $seq);
    }
}
