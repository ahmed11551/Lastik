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
use Autometria\Models\Reservation;
use Autometria\Services\OrderService;
use Autometria\Services\PaymentService;
use Autometria\Support\AuditLog as AuditWriter;
use Tests\Support\AcceptanceFixture;

/**
 * Приёмка 49.12 — журнал действий.
 */
beforeEach(function (): void {
    $this->fx = AcceptanceFixture::make('49-12-'.uniqid());
});

it('records who/when/tenant/object/action/old/new for order payment reserve and shift', function (): void {
    $fx = $this->fx;

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
            'price' => 1000,
            'warehouse_id' => $fx->warehouse->id,
        ]],
        scenario: 'without_installation',
    ), $fx->user->id);

    app(PaymentService::class)->accept(
        $fx->tenant->id,
        $order->id,
        [['method' => 'cash', 'amount' => 1000]],
        $fx->user->id,
        $fx->shift->id,
    );

    $actions = AuditLog::query()->withoutGlobalScopes()
        ->where('tenant_id', $fx->tenant->id)
        ->pluck('action')
        ->all();

    expect($actions)->toContain('order.created');
    expect($actions)->toContain('payment.created');

    $orderLog = AuditLog::query()->withoutGlobalScopes()
        ->where('tenant_id', $fx->tenant->id)
        ->where('action', 'order.created')
        ->first();

    expect((int) $orderLog->user_id)->toBe($fx->user->id);
    expect($orderLog->object_type)->not->toBeEmpty();
    expect((int) $orderLog->object_id)->toBe($order->id);
    expect($orderLog->new)->toBeArray();
    expect($orderLog->created_at)->not->toBeNull();
    expect((int) ($orderLog->metadata['location_id'] ?? 0))->toBe($fx->location->id);

    $reservation = Reservation::query()->withoutGlobalScopes()
        ->where('tenant_id', $fx->tenant->id)
        ->where('status', Reservation::STATUS_ACTIVE)
        ->first();

    expect($reservation)->not->toBeNull();

    AuditWriter::write(
        $fx->tenant->id,
        $fx->user->id,
        'stock.reserved',
        Reservation::class,
        (int) $reservation->id,
        [],
        ['qty' => $reservation->qty, 'stock_id' => $reservation->stock_id],
        ['location_id' => $fx->location->id],
    );

    expect(
        AuditLog::query()->withoutGlobalScopes()
            ->where('action', 'stock.reserved')
            ->where('tenant_id', $fx->tenant->id)
            ->exists()
    )->toBeTrue();
});

it('forbids update and delete of audit log records', function (): void {
    $fx = $this->fx;

    $log = AuditWriter::write(
        $fx->tenant->id,
        $fx->user->id,
        'test.event',
        'Test',
        1,
        ['a' => 1],
        ['a' => 2],
    );

    expect(fn () => $log->update(['action' => 'forged']))
        ->toThrow(RuntimeException::class, 'audit_logs is append-only');

    expect(fn () => $log->delete())
        ->toThrow(RuntimeException::class, 'audit_logs is append-only');
});
