<?php

/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author Себиев Ахмед Сулейманович
 * @license Proprietary & Confidential.
 */

declare(strict_types=1);

namespace Autometria\Http\Controllers;

use Autometria\Models\Stock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StockController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(100, max(1, (int) $request->integer('per_page', 50)));
        $q = trim((string) $request->query('q', ''));
        $category = trim((string) $request->query('category', ''));
        $warehouseId = $request->query('warehouse_id');
        $warehouseName = trim((string) $request->query('warehouse', ''));
        $likeOp = DB::getDriverName() === 'pgsql' ? 'ilike' : 'like';

        $query = Stock::query()->with(['product', 'warehouse']);

        $query->whereHas('product', function ($builder) use ($category, $likeOp): void {
            $builder->where(function ($inner): void {
                $inner->where('type', 'product')->orWhereNull('type');
            });
            if ($category !== '' && strtolower($category) !== 'all') {
                $builder->where('category', $category);
            }
        });

        if ($warehouseId !== null && $warehouseId !== '' && $warehouseId !== 'all') {
            $query->where('warehouse_id', (int) $warehouseId);
        } elseif ($warehouseName !== '' && strtolower($warehouseName) !== 'all') {
            $query->whereHas('warehouse', fn ($b) => $b->where('name', $warehouseName));
        }

        if ($q !== '') {
            $like = '%'.$q.'%';
            $query->where(function ($builder) use ($like, $likeOp): void {
                $builder
                    ->whereHas('product', function ($p) use ($like, $likeOp): void {
                        $p->where('name', $likeOp, $like)
                            ->orWhere('article', $likeOp, $like)
                            ->orWhere('external_id', $likeOp, $like)
                            ->orWhere('brand', $likeOp, $like);
                    })
                    ->orWhereHas('warehouse', fn ($w) => $w->where('name', $likeOp, $like));
            });
        }

        $paginator = $query->orderBy('id')->paginate($perPage);

        $data = collect($paginator->items())->map(function (Stock $stock): array {
            $product = $stock->product;
            $warehouse = $stock->warehouse;
            $available = (float) $stock->available;
            $status = $available <= 0 ? 'critical' : ($available <= 3 ? 'low' : 'ok');

            return [
                'id' => $stock->id,
                'product_id' => $stock->product_id,
                'sku' => (string) ($product?->article ?: ('ID-'.$stock->product_id)),
                'oem' => (string) ($product?->external_id ?: '—'),
                'name' => (string) ($product?->name ?: '—'),
                'category' => (string) ($product?->category ?: '—'),
                'warehouse' => (string) ($warehouse?->name ?: '—'),
                'warehouse_id' => $stock->warehouse_id,
                'cell' => (string) (data_get($product?->radius_modifier, 'cell') ?: '—'),
                'available' => (int) round($available),
                'reserved' => (int) round((float) $stock->reserved),
                'price' => (float) ($product?->base_price ?? 0),
                'status' => $status,
                'is_marked' => (bool) ($product?->is_marked ?? false),
                'marking_type' => $product?->marking_type,
                'is_egais' => (bool) ($product?->is_egais ?? false),
            ];
        })->values();

        $categoriesQuery = DB::table('products_services')
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->distinct()
            ->orderBy('category');

        if (function_exists('tenant_id') && tenant_id()) {
            $categoriesQuery->where('tenant_id', tenant_id());
        }

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'categories' => $categoriesQuery->pluck('category')->values(),
            ],
        ]);
    }
}
