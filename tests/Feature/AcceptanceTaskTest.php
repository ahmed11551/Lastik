<?php

/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author Себиев Ахмед Сулейманович
 * @license Proprietary & Confidential.
 */

declare(strict_types=1);

use Autometria\DTOs\CreateOrderDTO;
use Autometria\Models\AuditLog;
use Autometria\Models\Task;
use Autometria\Services\OrderService;
use Autometria\Services\TaskService;
use Tests\Support\AcceptanceFixture;

/**
 * Приёмка 49.20 — базовые задачи.
 */
beforeEach(function (): void {
    $this->fx = AcceptanceFixture::make('49-20-'.uniqid());
});

it('creates assigns links completes and cancels tasks with audit', function (): void {
    $fx = $this->fx;
    $tasks = app(TaskService::class);

    $order = app(OrderService::class)->create(new CreateOrderDTO(
        tenantId: $fx->tenant->id,
        customerId: $fx->customer->id,
        locationId: $fx->location->id,
        assignedSellerId: $fx->user->id,
        masterId: 0,
        items: [[
            'type' => 'product',
            'product_id' => $fx->product->id,
            'qty' => 1,
            'price' => 500,
            'warehouse_id' => $fx->warehouse->id,
        ]],
        scenario: 'without_installation',
    ), $fx->user->id);

    $task = $tasks->create($fx->tenant->id, $fx->user->id, [
        'title' => 'Перезвонить клиенту',
        'body' => 'Уточнить время установки',
        'assigned_to' => $fx->master->id,
        'order_id' => $order->id,
        'customer_id' => $fx->customer->id,
        'vehicle_id' => $fx->vehicle->id,
        'location_id' => $fx->location->id,
    ]);

    expect($task->status)->toBe(Task::STATUS_OPEN);
    expect($task->assigned_to)->toBe($fx->master->id);
    expect($task->order_id)->toBe($order->id);
    expect($task->customer_id)->toBe($fx->customer->id);
    expect($task->vehicle_id)->toBe($fx->vehicle->id);

    $done = $tasks->complete($task, $fx->master->id);
    expect($done->status)->toBe(Task::STATUS_DONE);
    expect($done->completed_at)->not->toBeNull();

    $task2 = $tasks->create($fx->tenant->id, $fx->user->id, [
        'title' => 'Отменённая задача',
        'assigned_to' => $fx->user->id,
        'order_id' => $order->id,
    ]);

    $cancelled = $tasks->cancel($task2, $fx->user->id, 'Клиент отказался');
    expect($cancelled->status)->toBe(Task::STATUS_CANCELLED);
    expect($cancelled->cancel_reason)->toBe('Клиент отказался');

    $actions = AuditLog::query()->withoutGlobalScopes()
        ->where('tenant_id', $fx->tenant->id)
        ->whereIn('action', ['task.created', 'task.completed', 'task.cancelled'])
        ->pluck('action')
        ->unique()
        ->values()
        ->all();

    expect($actions)->toContain('task.created');
    expect($actions)->toContain('task.completed');
    expect($actions)->toContain('task.cancelled');
});

it('requires cancel reason', function (): void {
    $fx = $this->fx;
    $task = app(TaskService::class)->create($fx->tenant->id, $fx->user->id, [
        'title' => 'Без причины',
    ]);

    expect(fn () => app(TaskService::class)->cancel($task, $fx->user->id, '  '))
        ->toThrow(InvalidArgumentException::class);
});
