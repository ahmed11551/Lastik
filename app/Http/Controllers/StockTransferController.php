<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\Domain\InsufficientStockException;
use App\Services\StockTransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockTransferController extends Controller
{
    public function __construct(
        private readonly StockTransferService $transfers,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products_services,id'],
            'from_warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'to_warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'qty' => ['required', 'numeric', 'min:0.001'],
            'reason' => ['required', 'string', 'min:3'],
        ]);

        try {
            $transfer = $this->transfers->transfer(
                (int) $request->user()->tenant_id,
                (int) $validated['product_id'],
                (int) $validated['from_warehouse_id'],
                (int) $validated['to_warehouse_id'],
                (float) $validated['qty'],
                $validated['reason'],
                (int) $request->user()->id,
            );
        } catch (InsufficientStockException $e) {
            return response()->json(['message' => $e->getMessage(), 'error' => 'available_less_than_qty'], 409);
        }

        return response()->json(['data' => $transfer], 201);
    }
}
