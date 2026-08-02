<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Http\Controllers;

use Autometria\Enums\InventoryDocumentTypeEnum;
use Autometria\Exceptions\Domain\InsufficientStockException;
use Autometria\Models\InventoryDocument;
use Autometria\Services\StockDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Block 4.2 — API складских документов (frontend contract RECEIPT/WRITE_OFF/TRANSFER/INVENTORY).
 */
final class InventoryDocumentController extends Controller
{
    public function __construct(
        private readonly StockDocumentService $documents,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);

        $query = InventoryDocument::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->with('items')
            ->orderByDesc('id');

        if ($type = $request->query('type')) {
            try {
                $canonical = InventoryDocumentTypeEnum::normalize((string) $type);
                $query->where('type', $canonical->value);
            } catch (InvalidArgumentException) {
                $query->whereRaw('1=0');
            }
        }

        if ($status = $request->query('status')) {
            $query->where('status', strtoupper((string) $status));
        }

        if ($warehouseId = $request->query('warehouse_id')) {
            $wid = (int) $warehouseId;
            $query->where(function ($q) use ($wid) {
                $q->where('warehouse_id', $wid)->orWhere('target_warehouse_id', $wid);
            });
        }

        if ($from = $request->query('date_from')) {
            $query->where('created_at', '>=', (string) $from);
        }
        if ($to = $request->query('date_to')) {
            $dateTo = (string) $to;
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo) === 1) {
                $dateTo .= ' 23:59:59';
            }
            $query->where('created_at', '<=', $dateTo);
        }

        $rows = $query->limit(200)->get()->map(fn (InventoryDocument $d) => $this->serialize($d))->values();

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $userId = (int) $request->user()->id;

        $data = $request->validate([
            'type' => ['required', 'string', Rule::in(InventoryDocumentTypeEnum::apiValues())],
            'warehouse_id' => ['nullable', 'integer'],
            'from_warehouse_id' => ['nullable', 'integer'],
            'to_warehouse_id' => ['nullable', 'integer'],
            'target_warehouse_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.qty' => ['nullable', 'numeric', 'min:0.001'],
            'items.*.quantity' => ['nullable', 'numeric', 'min:0.001'],
            'items.*.price' => ['nullable', 'numeric', 'min:0'],
            'items.*.cost_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.reason' => ['nullable', 'string', 'max:500'],
            'items.*.sku' => ['nullable', 'string', 'max:100'],
            'items.*.name' => ['nullable', 'string', 'max:255'],
        ]);

        $warehouseId = (int) ($data['from_warehouse_id'] ?? $data['warehouse_id'] ?? 0);
        $targetId = isset($data['to_warehouse_id'])
            ? (int) $data['to_warehouse_id']
            : (isset($data['target_warehouse_id']) ? (int) $data['target_warehouse_id'] : null);

        if ($warehouseId <= 0) {
            return response()->json(['message' => 'warehouse_id / from_warehouse_id is required'], 422);
        }

        try {
            $doc = $this->documents->createDraft(
                $tenantId,
                (string) $data['type'],
                $warehouseId,
                $targetId,
                $data['items'],
                $userId,
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->serialize($doc->load('items'))], 201);
    }

    public function post(Request $request, int $id): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $userId = (int) $request->user()->id;

        try {
            $doc = $this->documents->post($tenantId, $id, $userId);
        } catch (InsufficientStockException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'InsufficientStockException'], 422);
        } catch (InvalidArgumentException|RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Failed to post document'], 500);
        }

        return response()->json(['data' => $this->serialize($doc->load('items'))]);
    }

    private function serialize(InventoryDocument $doc): array
    {
        $items = $doc->relationLoaded('items') ? $doc->items : $doc->items()->get();

        return [
            'id' => $doc->id,
            'number' => $doc->number,
            'type' => $doc->type,
            'status' => $doc->status,
            'warehouse_id' => $doc->warehouse_id,
            'from_warehouse_id' => $doc->warehouse_id,
            'to_warehouse_id' => $doc->target_warehouse_id,
            'target_warehouse_id' => $doc->target_warehouse_id,
            'created_by' => $doc->created_by,
            'posted_at' => optional($doc->posted_at)?->toIso8601String(),
            'created_at' => optional($doc->created_at)?->toIso8601String(),
            'total_amount' => round((float) $items->sum(fn ($i) => (float) $i->quantity * (float) $i->cost_price), 2),
            'total' => round((float) $items->sum(fn ($i) => (float) $i->quantity * (float) $i->cost_price), 2),
            'items' => $items->map(fn ($i) => [
                'id' => $i->id,
                'product_id' => $i->product_id,
                'qty' => (float) $i->quantity,
                'quantity' => (float) $i->quantity,
                'price' => (float) $i->cost_price,
                'cost_price' => (float) $i->cost_price,
                'reason' => $i->reason,
                'sku' => $i->sku,
                'name' => $i->name,
            ])->values()->all(),
        ];
    }

    private function tenantId(Request $request): int
    {
        $id = (int) ($request->user()?->tenant_id ?? tenant_id() ?? 0);
        abort_unless($id > 0, 422, 'Tenant context required');

        return $id;
    }
}
