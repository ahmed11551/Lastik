<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Http\Controllers\Wms;

use Autometria\Http\Controllers\Controller;
use Autometria\Models\SerialNumber;
use Autometria\Models\StockBatchCell;
use Autometria\Models\StorageCell;
use Autometria\Services\Wms\SerialNumberService;
use Autometria\Services\Wms\StorageCellService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class WmsController extends Controller
{
    public function __construct(
        private readonly StorageCellService $cells,
        private readonly SerialNumberService $serials,
    ) {}

    public function listCells(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $warehouseId = $request->filled('warehouse_id') ? (int) $request->integer('warehouse_id') : null;
        $activeOnly = $request->boolean('active_only');

        $rows = $this->cells->list($tenantId, $warehouseId, $activeOnly)
            ->map(fn (StorageCell $c) => $this->serializeCell($c))
            ->values();

        return response()->json(['data' => $rows]);
    }

    public function storeCell(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer'],
            'code' => ['required', 'string', 'max:64'],
            'zone' => ['nullable', 'string', 'max:32'],
            'rack' => ['nullable', 'string', 'max:32'],
            'shelf' => ['nullable', 'string', 'max:32'],
            'bin' => ['nullable', 'string', 'max:32'],
            'description' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        try {
            $cell = $this->cells->create(
                $tenantId,
                $data,
                (int) ($request->user()?->id ?? 0) ?: null,
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->serializeCell($cell)], 201);
    }

    public function updateCell(Request $request, int $id): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $data = $request->validate([
            'code' => ['sometimes', 'string', 'max:64'],
            'zone' => ['nullable', 'string', 'max:32'],
            'rack' => ['nullable', 'string', 'max:32'],
            'shelf' => ['nullable', 'string', 'max:32'],
            'bin' => ['nullable', 'string', 'max:32'],
            'description' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        try {
            $cell = $this->cells->update(
                $tenantId,
                $id,
                $data,
                (int) ($request->user()?->id ?? 0) ?: null,
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->serializeCell($cell)]);
    }

    public function destroyCell(Request $request, int $id): JsonResponse
    {
        $tenantId = $this->tenantId($request);

        try {
            $this->cells->delete(
                $tenantId,
                $id,
                (int) ($request->user()?->id ?? 0) ?: null,
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true]);
    }

    public function placeBatch(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $data = $request->validate([
            'stock_batch_id' => ['required', 'integer'],
            'storage_cell_id' => ['required', 'integer'],
            'qty' => ['required', 'numeric', 'min:0.001'],
        ]);

        try {
            $row = $this->cells->placeBatch(
                $tenantId,
                (int) $data['stock_batch_id'],
                (int) $data['storage_cell_id'],
                (float) $data['qty'],
                (int) ($request->user()?->id ?? 0) ?: null,
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->serializePlacement($row)], 201);
    }

    public function moveBatch(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $data = $request->validate([
            'stock_batch_id' => ['required', 'integer'],
            'from_cell_id' => ['required', 'integer'],
            'to_cell_id' => ['required', 'integer'],
            'qty' => ['required', 'numeric', 'min:0.001'],
        ]);

        try {
            $row = $this->cells->moveBatch(
                $tenantId,
                (int) $data['stock_batch_id'],
                (int) $data['from_cell_id'],
                (int) $data['to_cell_id'],
                (float) $data['qty'],
                (int) ($request->user()?->id ?? 0) ?: null,
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->serializePlacement($row)]);
    }

    public function listSerials(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $productId = $request->filled('product_id') ? (int) $request->integer('product_id') : null;
        $status = $request->string('status')->toString() ?: null;
        $batchId = $request->filled('stock_batch_id') ? (int) $request->integer('stock_batch_id') : null;

        $rows = $this->serials->list($tenantId, $productId, $status, $batchId)
            ->map(fn (SerialNumber $s) => $this->serializeSerial($s))
            ->values();

        return response()->json(['data' => $rows]);
    }

    public function receiveSerials(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'stock_batch_id' => ['required', 'integer'],
            'serials' => ['required', 'array', 'min:1'],
            'serials.*' => ['required', 'string', 'max:128'],
            'warehouse_id' => ['nullable', 'integer'],
        ]);

        try {
            $created = $this->serials->receive(
                $tenantId,
                (int) $data['product_id'],
                (int) $data['stock_batch_id'],
                $data['serials'],
                isset($data['warehouse_id']) ? (int) $data['warehouse_id'] : null,
                (int) ($request->user()?->id ?? 0) ?: null,
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => array_map(fn (SerialNumber $s) => $this->serializeSerial($s), $created),
        ], 201);
    }

    public function markSerialsSold(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $data = $request->validate([
            'serials' => ['required', 'array', 'min:1'],
        ]);

        $count = $this->serials->markSold(
            $tenantId,
            $data['serials'],
            (int) ($request->user()?->id ?? 0) ?: null,
        );

        return response()->json(['data' => ['updated' => $count]]);
    }

    private function tenantId(Request $request): int
    {
        $tenantId = (int) ($request->user()?->tenant_id ?? tenant_id() ?? 0);
        abort_unless($tenantId > 0, 422, 'Tenant context required');

        return $tenantId;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeCell(StorageCell $c): array
    {
        return [
            'id' => $c->id,
            'warehouse_id' => $c->warehouse_id,
            'warehouse_name' => $c->warehouse?->name,
            'code' => $c->code,
            'zone' => $c->zone,
            'rack' => $c->rack,
            'shelf' => $c->shelf,
            'bin' => $c->bin,
            'description' => $c->description,
            'is_active' => (bool) $c->is_active,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializePlacement(StockBatchCell $row): array
    {
        return [
            'id' => $row->id,
            'stock_batch_id' => $row->stock_batch_id,
            'storage_cell_id' => $row->storage_cell_id,
            'cell_code' => $row->cell?->code,
            'quantity' => round((float) $row->quantity, 3),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeSerial(SerialNumber $s): array
    {
        return [
            'id' => $s->id,
            'product_id' => $s->product_id,
            'product_name' => $s->product?->name,
            'stock_batch_id' => $s->stock_batch_id,
            'warehouse_id' => $s->warehouse_id,
            'serial' => $s->serial,
            'status' => $s->status,
        ];
    }
}
