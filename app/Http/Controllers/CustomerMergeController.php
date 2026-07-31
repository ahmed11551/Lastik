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

use Autometria\Services\CustomerMergeService;
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
