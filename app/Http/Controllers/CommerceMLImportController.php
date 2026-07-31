<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\StockConflict;
use App\Services\Import\CommerceMLImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommerceMLImportController extends Controller
{
    public function __construct(
        private readonly CommerceMLImportService $imports,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:51200'],
        ]);

        $uploaded = $request->file('file');
        $absolute = $uploaded->getRealPath();

        $job = $this->imports->import(
            (string) $absolute,
            (int) $request->user()->tenant_id,
            (int) $request->user()->id,
        );

        return response()->json(['data' => $job], 201);
    }

    public function conflicts(Request $request): array
    {
        $tenantId = (int) $request->user()->tenant_id;

        return [
            'data' => StockConflict::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where(function ($q): void {
                    $q->where('resolved', false)->orWhereNull('resolved');
                })
                ->latest('id')
                ->get(),
        ];
    }

    public function resolveConflict(Request $request, StockConflict $conflict): JsonResponse
    {
        abort_unless((int) $conflict->tenant_id === (int) $request->user()->tenant_id, 403);

        $conflict->update(['resolved' => true]);

        return response()->json(['data' => $conflict->fresh()]);
    }
}
