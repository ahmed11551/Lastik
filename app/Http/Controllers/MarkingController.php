<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Http\Controllers;

use Autometria\Exceptions\Domain\InvalidMarkingCodeException;
use Autometria\Models\MarkingCode;
use Autometria\Services\Marking\ChestnyZnakClient;
use Autometria\Services\Marking\MarkingValidationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MarkingController extends Controller
{
    public function __construct(
        private readonly MarkingValidationService $marking,
        private readonly ChestnyZnakClient $chestnyZnak,
    ) {}

    /**
     * POST /api/v1/regulatory/marking/verify
     */
    public function verify(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        set_current_tenant_id($tenantId);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:255'],
            'marking_code' => ['nullable', 'string', 'max:255'],
            'product_id' => ['nullable', 'integer'],
        ]);

        $raw = (string) ($data['code'] ?? $data['marking_code'] ?? '');

        try {
            $local = $this->marking->validateDataMatrix($raw, isset($data['product_id']) ? (int) $data['product_id'] : null);
            $remote = $this->chestnyZnak->validate($local['raw'], $local);
        } catch (InvalidMarkingCodeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => $e->errorCode,
                'valid' => false,
            ], 422);
        }

        return response()->json([
            'data' => [
                'valid' => true,
                'gtin' => $local['gtin'],
                'serial' => $local['serial'],
                'status' => $local['status'],
                'marking_code_id' => $local['marking_code_id'],
                'chestny_znak' => $remote['status']->value,
            ],
        ]);
    }

    /**
     * GET /api/v1/regulatory/marking/codes
     */
    public function codes(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);

        $q = MarkingCode::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('id');

        if ($request->filled('status')) {
            $q->where('status', (string) $request->input('status'));
        }

        $rows = $q->limit(200)->get()->map(fn (MarkingCode $m) => [
            'id' => $m->id,
            'code' => $m->code,
            'gtin' => $m->gtin,
            'serial' => $m->serial,
            'status' => $m->status,
            'product_id' => $m->product_id,
            'receipt_id' => $m->receipt_id,
            'created_at' => optional($m->created_at)?->toIso8601String(),
            'updated_at' => optional($m->updated_at)?->toIso8601String(),
        ]);

        return response()->json(['data' => $rows]);
    }

    private function tenantId(Request $request): int
    {
        $id = (int) ($request->user()?->tenant_id ?? tenant_id() ?? 0);
        abort_unless($id > 0, 422, 'Tenant context required');

        return $id;
    }
}
