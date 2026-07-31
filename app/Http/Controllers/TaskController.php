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

use Autometria\Models\Task;
use Autometria\Services\TaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    public function __construct(
        private readonly TaskService $tasks,
    ) {}

    public function index(Request $request): array
    {
        return [
            'data' => Task::query()->withoutGlobalScopes()
                ->where('tenant_id', $request->user()->tenant_id)
                ->latest('id')
                ->get(),
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenant_id' => ['prohibited'],
            'created_by' => ['prohibited'],
            'updated_by' => ['prohibited'],
            'title' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'order_id' => ['nullable', 'integer', 'exists:orders,id'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'vehicle_id' => ['nullable', 'integer', 'exists:vehicles,id'],
            // location только из контекста сессии
            'location_id' => ['prohibited'],
        ]);

        $validated['location_id'] = location_id() ?? $request->user()->location_id;

        $task = $this->tasks->create(
            (int) $request->user()->tenant_id,
            (int) $request->user()->id,
            $validated,
        );

        return response()->json(['data' => $task], 201);
    }

    public function complete(Request $request, Task $task): JsonResponse
    {
        abort_unless((int) $task->tenant_id === (int) $request->user()->tenant_id, 403);

        $done = $this->tasks->complete($task, (int) $request->user()->id);

        return response()->json(['data' => $done]);
    }

    public function cancel(Request $request, Task $task): JsonResponse
    {
        abort_unless((int) $task->tenant_id === (int) $request->user()->tenant_id, 403);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3'],
        ]);

        $cancelled = $this->tasks->cancel($task, (int) $request->user()->id, $validated['reason']);

        return response()->json(['data' => $cancelled]);
    }
}
