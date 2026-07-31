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

use Autometria\Models\Module;
use Autometria\Services\ModuleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ModuleController extends Controller
{
    public function __construct(
        private readonly ModuleService $modules,
    ) {}

    public function index(Request $request): array
    {
        $tenantId = (int) $request->user()->tenant_id;

        return [
            'data' => Module::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->orderBy('slug')
                ->get(),
        ];
    }

    public function enable(Request $request, string $slug): JsonResponse
    {
        $module = $this->modules->enable(
            (int) $request->user()->tenant_id,
            $slug,
            (int) $request->user()->id,
        );

        return response()->json(['data' => $module]);
    }

    public function disable(Request $request, string $slug): JsonResponse
    {
        $module = $this->modules->disable(
            (int) $request->user()->tenant_id,
            $slug,
            (int) $request->user()->id,
        );

        return response()->json(['data' => $module]);
    }
}
