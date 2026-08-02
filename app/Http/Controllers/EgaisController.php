<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Http\Controllers;

use Autometria\Services\Marking\EgaisService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class EgaisController extends Controller
{
    public function __construct(
        private readonly EgaisService $egais,
    ) {}

    /**
     * POST /api/v1/regulatory/egais/unseal
     */
    public function unseal(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        set_current_tenant_id($tenantId);

        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'volume' => ['required', 'numeric', 'min:0.001'],
            'fsrar_id' => ['required', 'string', 'max:64'],
        ]);

        try {
            $doc = $this->egais->createEgaisUnsealAct(
                (int) $data['product_id'],
                (float) $data['volume'],
                (string) $data['fsrar_id'],
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => [
                'id' => $doc->id,
                'doc_type' => $doc->doc_type,
                'fsrar_id' => $doc->fsrar_id,
                'status' => $doc->status,
                'payload' => $doc->payload,
            ],
        ], 201);
    }

    private function tenantId(Request $request): int
    {
        $id = (int) ($request->user()?->tenant_id ?? tenant_id() ?? 0);
        abort_unless($id > 0, 422, 'Tenant context required');

        return $id;
    }
}
