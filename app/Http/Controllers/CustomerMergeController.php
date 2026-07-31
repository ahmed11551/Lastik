<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\CustomerMergeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerMergeController extends Controller
{
    public function __construct(
        private readonly CustomerMergeService $merges,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'primary_customer_id' => ['required', 'integer', 'exists:customers,id'],
            'merged_customer_id' => ['required', 'integer', 'exists:customers,id', 'different:primary_customer_id'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $merge = $this->merges->merge(
            (int) $request->user()->tenant_id,
            (int) $validated['primary_customer_id'],
            (int) $validated['merged_customer_id'],
            (int) $request->user()->id,
            $validated['reason'] ?? null,
        );

        return response()->json(['data' => $merge], 201);
    }
}
