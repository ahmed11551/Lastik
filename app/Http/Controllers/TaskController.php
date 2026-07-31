<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Task;
use App\Services\TaskService;
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
            'title' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'order_id' => ['nullable', 'integer', 'exists:orders,id'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'vehicle_id' => ['nullable', 'integer', 'exists:vehicles,id'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
        ]);

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
