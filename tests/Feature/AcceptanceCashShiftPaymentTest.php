<?php

/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author Себиев Ахмед Сулейманович
 * @license Proprietary & Confidential.
 */

declare(strict_types=1);

use Autometria\DTOs\CreateOrderDTO;
use Autometria\Exceptions\Domain\ShiftAlreadyClosedException;
use Autometria\Models\AuditLog;
use Autometria\Models\CashMovement;
use Autometria\Models\MoneyRecipient;
use Autometria\Models\PaymentCorrection;
use Autometria\Services\Cash\CashShiftService;
use Autometria\Services\OrderService;
use Autometria\Services\PaymentService;
use Tests\Support\AcceptanceFixture;

use function Pest\Laravel\actingAs;

/**
 * Приёмка 49.9 — оплата, касса, смена.
 */
beforeEach(function (): void {
    $this->fx = AcceptanceFixture::make('49-9-'.uniqid());
});

it('opens shift and accepts cash, card and transfer payments including mixed', function (): void {
    $fx = $this->fx;

    $order = app(OrderService::class)->create(new CreateOrderDTO(
        tenantId: $fx->tenant->id,
        customerId: $fx->customer->id,
        locationId: $fx->location->id,
        assignedSellerId: $fx->user->id,
        masterId: $fx->master->id,
        items: [[
            'type' => 'product',
            'product_id' => $fx->product->id,
            'qty' => 1,
            'price' => 5000,
            'discount' => 0,
            'warehouse_id' => $fx->warehouse->id,
        ]],
        vehicleId: $fx->vehicle->id,
        scenario: 'without_installation',
    ), $fx->user->id);

    $cashRecipient = MoneyRecipient::query()->withoutGlobalScopes()->create([
        'tenant_id' => $fx->tenant->id,
        'type' => MoneyRecipient::TYPE_CASH_DESK,
        'name' => 'Касса точки А',
        'is_active' => true,
    ]);

    $cardRecipient = MoneyRecipient::query()->withoutGlobalScopes()->create([
        'tenant_id' => $fx->tenant->id,
        'type' => MoneyRecipient::TYPE_CARD_FIO,
        'name' => 'Карта Иванова И.И.',
        'details' => '4276****1234',
        'is_active' => true,
    ]);

    expect($cashRecipient->type)->toBe(MoneyRecipient::TYPE_CASH_DESK);
    expect($cardRecipient->type)->toBe(MoneyRecipient::TYPE_CARD_FIO);

    $payments = app(PaymentService::class)->accept(
        $fx->tenant->id,
        $order->id,
        [
            ['method' => 'cash', 'amount' => 2000, 'payee_id' => $fx->user->id],
            ['method' => 'card', 'amount' => 2000, 'payee_id' => $fx->user->id],
            ['method' => 'transfer', 'amount' => 1000, 'payee_id' => $fx->user->id],
        ],
        $fx->user->id,
        $fx->shift->id,
    );

    expect($payments)->toHaveCount(3);
    expect(collect($payments)->sum(fn ($p) => (float) $p->amount))->toBe(5000.0);
    expect(collect($payments)->pluck('method')->sort()->values()->all())
        ->toBe(['card', 'cash', 'transfer']);

    $order->refresh();
    expect($order->payment_status)->toBe('paid');
});

it('supports inkasso and withdrawal on open shift and blocks payment rewrite after close', function (): void {
    $fx = $this->fx;
    actingAs($fx->user);

    $cash = app(CashShiftService::class);

    $inkasso = $cash->inkasso($fx->shift->fresh(), 500.0, 'Инкассация вечер');
    $withdrawal = $cash->withdrawal($fx->shift->fresh(), 200.0, 'Выемка размен');

    expect($inkasso->type)->toBe(CashMovement::TYPE_INKASSO);
    expect($withdrawal->type)->toBe(CashMovement::TYPE_WITHDRAWAL);

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

    $payments = app(PaymentService::class)->accept(
        $fx->tenant->id,
        $order->id,
        [['method' => 'cash', 'amount' => 1000]],
        $fx->user->id,
        $fx->shift->id,
    );

    $payment = $payments[0];

    // Корректировка допустима только пока смена открыта
    $correction = app(PaymentService::class)->correct(
        $payment,
        900.0,
        'Ошибка кассира до закрытия смены',
        $fx->user->id,
    );

    expect($correction)->toBeInstanceOf(PaymentCorrection::class);
    expect((float) $correction->old_amount)->toBe(1000.0);
    expect((float) $correction->new_amount)->toBe(900.0);

    $closed = $cash->close($fx->shift->fresh());
    expect($closed->closed_at)->not->toBeNull();
    expect($closed->totals)->toHaveKeys(['cash', 'inkasso', 'withdrawal']);
    expect((float) $closed->totals['cash'])->toBe(900.0);
    expect((float) $closed->totals['inkasso'])->toBe(500.0);
    expect((float) $closed->totals['withdrawal'])->toBe(200.0);

    // Прямая перезапись после закрытия смены запрещена сервисом accept
    expect(fn () => app(PaymentService::class)->accept(
        $fx->tenant->id,
        $order->id,
        [['method' => 'cash', 'amount' => 50]],
        $fx->user->id,
        $fx->shift->id,
    ))->toThrow(RuntimeException::class);

    // Корректировка после закрытия смены запрещена
    expect(fn () => app(PaymentService::class)->correct(
        $payment->fresh(),
        800.0,
        'Попытка после закрытия',
        $fx->user->id,
    ))->toThrow(ShiftAlreadyClosedException::class);

    $log = AuditLog::query()->withoutGlobalScopes()
        ->where('tenant_id', $fx->tenant->id)
        ->where('action', 'payment.corrected')
        ->first();

    expect($log)->not->toBeNull();
    expect($log->reason)->toBe('Ошибка кассира до закрытия смены');
});

it('writes audit on shift open via service', function (): void {
    $fx = $this->fx;
    $fx->shift->update(['closed_at' => now(), 'status' => 'closed']);

    actingAs($fx->user);
    set_current_tenant_id($fx->tenant->id);

    $opened = app(CashShiftService::class)->open(
        $fx->tenant->id,
        $fx->location->id,
        $fx->user->id,
    );

    expect($opened->status)->toBe('opened');
    expect($opened->closed_at)->toBeNull();

    $log = AuditLog::query()->withoutGlobalScopes()
        ->where('tenant_id', $fx->tenant->id)
        ->where('action', 'cash_shift.open')
        ->latest('id')
        ->first();

    expect($log)->not->toBeNull();
    expect((int) $log->object_id)->toBe($opened->id);
});
