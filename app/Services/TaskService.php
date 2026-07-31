<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Task;
use App\Support\AuditLog;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class TaskService
{
    /**
     * @param array{
     *   title: string,
     *   body?: string|null,
     *   assigned_to?: int|null,
     *   order_id?: int|null,
     *   customer_id?: int|null,
     *   vehicle_id?: int|null,
     *   location_id?: int|null
     * } $payload
     */
    public function create(int $tenantId, int $createdBy, array $payload): Task
    {
        $task = Task::query()->withoutGlobalScopes()->forceCreate([
            'tenant_id' => $tenantId,
            'location_id' => $payload['location_id'] ?? null,
            'created_by' => $createdBy,
            'assigned_to' => $payload['assigned_to'] ?? null,
            'order_id' => $payload['order_id'] ?? null,
            'customer_id' => $payload['customer_id'] ?? null,
            'vehicle_id' => $payload['vehicle_id'] ?? null,
            'title' => $payload['title'],
            'body' => $payload['body'] ?? null,
            'status' => Task::STATUS_OPEN,
        ]);

        AuditLog::write(
            $tenantId,
            $createdBy,
            'task.created',
            Task::class,
            (int) $task->id,
            [],
            $task->only(['title', 'assigned_to', 'order_id', 'customer_id', 'vehicle_id', 'status']),
        );

        return $task;
    }

    public function complete(Task $task, int $userId): Task
    {
        return DB::transaction(function () use ($task, $userId): Task {
            $task = Task::query()->withoutGlobalScopes()->whereKey($task->id)->lockForUpdate()->firstOrFail();

            if ($task->status !== Task::STATUS_OPEN) {
                throw new InvalidArgumentException('Only open tasks can be completed');
            }

            $old = ['status' => $task->status];
            $task->update([
                'status' => Task::STATUS_DONE,
                'completed_at' => now(),
            ]);

            AuditLog::write(
                (int) $task->tenant_id,
                $userId,
                'task.completed',
                Task::class,
                (int) $task->id,
                $old,
                ['status' => Task::STATUS_DONE],
            );

            return $task->fresh();
        });
    }

    public function cancel(Task $task, int $userId, string $reason): Task
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Cancel reason is required');
        }

        return DB::transaction(function () use ($task, $userId, $reason): Task {
            $task = Task::query()->withoutGlobalScopes()->whereKey($task->id)->lockForUpdate()->firstOrFail();

            $old = ['status' => $task->status];
            $task->update([
                'status' => Task::STATUS_CANCELLED,
                'cancel_reason' => $reason,
            ]);

            AuditLog::write(
                (int) $task->tenant_id,
                $userId,
                'task.cancelled',
                Task::class,
                (int) $task->id,
                $old,
                ['status' => Task::STATUS_CANCELLED],
                [],
                $reason,
            );

            return $task->fresh();
        });
    }
}
