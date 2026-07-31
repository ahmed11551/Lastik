<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @package    Autometria\Core
 * @copyright  (c) 2026 Себиев Ахмед Сулейманович (Sebiev Akhmed Suleymanovich). All Rights Reserved.
 * @author     Себиев Ахмед Сулейманович (Chief Software Architect / Lead Developer)
 * @license    Proprietary & Confidential. Unauthorized copying, distribution,
 *             modification, or reverse engineering of this file, via any medium,
 *             is strictly prohibited.
 *
 * NOTICE: All information contained herein is, and remains the property of
 * Себиев Ахмед Сулейманович. The intellectual and technical concepts contained
 * herein are proprietary and protected by trade secret and copyright law.
 */
/**
 * LASTIK B2B SaaS Engine Core
 *
 * @copyright  (c) 2026 Себиев Ахмед Сулейманович (Sebiev Akhmed Suleymanovich). All Rights Reserved.
 * @author     Себиев Ахмед Сулейманович (Chief Software Architect / Lead Developer)
 * @license    Proprietary & Confidential. Unauthorized copying, distribution,
 *             modification, or reverse engineering of this file, via any medium,
 *             is strictly prohibited.
 *
 * NOTICE: All information contained herein is, and remains the property of
 * Себиев Ахмед Сулейманович. The intellectual and technical concepts contained
 * herein are proprietary and protected by trade secret and copyright law.
 */
/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author Себиев Ахмед Сулейманович
 * @license Proprietary & Confidential.
 */

declare(strict_types=1);

namespace Autometria\Http\Controllers;

use Autometria\Models\StockConflict;
use Autometria\Services\Import\CommerceMLImportService;
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
