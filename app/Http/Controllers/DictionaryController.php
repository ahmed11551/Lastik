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

use Autometria\Models\Dictionary;
use Autometria\Services\DictionaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DictionaryController extends Controller
{
    public function __construct(
        private readonly DictionaryService $dictionaries,
    ) {}

    public function index(Request $request): array
    {
        $type = $request->query('type');

        return [
            'data' => $this->dictionaries->list(
                (int) $request->user()->tenant_id,
                is_string($type) ? $type : null,
                ! $request->boolean('all'),
            ),
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'string', 'max:50'],
            'code' => ['required', 'string', 'max:50'],
            'label' => ['required', 'string', 'max:255'],
            'sort' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'meta' => ['nullable', 'array'],
        ]);

        $dict = $this->dictionaries->upsert(
            (int) $request->user()->tenant_id,
            $validated['type'],
            $validated['code'],
            $validated['label'],
            (int) $request->user()->id,
            $validated['sort'] ?? null,
            $validated['is_active'] ?? true,
            $validated['meta'] ?? null,
        );

        return response()->json(['data' => $dict], 201);
    }

    public function deactivate(Request $request, Dictionary $dictionary): JsonResponse
    {
        abort_unless((int) $dictionary->tenant_id === (int) $request->user()->tenant_id, 403);

        $dict = $this->dictionaries->deactivate(
            (int) $request->user()->tenant_id,
            (int) $dictionary->id,
            (int) $request->user()->id,
        );

        return response()->json(['data' => $dict]);
    }
}
