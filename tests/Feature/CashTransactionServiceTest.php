<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

use Autometria\Enums\ShiftStatusEnum;
use Autometria\Exceptions\Domain\ShiftExpiredException;
use Autometria\Models\CashMovement;
use Autometria\Models\CashShift;
use Autometria\Services\Cash\CashTransactionService;
use Tests\Support\AcceptanceFixture;
use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->fx = AcceptanceFixture::make('cash-tx-'.uniqid());
    set_current_tenant_id($this->fx->tenant->id);
    actingAs($this->fx->user);

    $this->shift = CashShift::query()->withoutGlobalScopes()->create([
        'tenant_id' => $this->fx->tenant->id,
        'location_id' => $this->fx->location->id,
        'user_id' => $this->fx->user->id,
        'opened_by' => $this->fx->user->id,
        'status' => ShiftStatusEnum::OPENED->value,
        'opening_amount' => 100,
        'opened_at' => now(),
        'expires_at' => now()->addHours(24),
        'closed_at' => null,
    ]);
});

it('deposit adds cash to active shift and logs audit', function (): void {
    $movement = app(CashTransactionService::class)->deposit($this->shift, 500.0, 'Пополнение');

    expect($movement)->toBeInstanceOf(CashMovement::class);
    expect((float) $movement->amount)->toBe(500.0);
    expect($movement->type)->toBe(CashMovement::TYPE_ADJUSTMENT);

    $shift = $this->shift->fresh();
    expect((float) ($shift->totals['deposit'] ?? 0))->toBe(500.0);
});

it('payOut withdraws cash from active shift', function (): void {
    $movement = app(CashTransactionService::class)->payOut($this->shift, 200.0, 'Выдача размена');

    expect($movement)->toBeInstanceOf(CashMovement::class);
    expect($movement->type)->toBe(CashMovement::TYPE_WITHDRAWAL);
    expect((float) ($this->shift->fresh()->totals['withdrawal'] ?? 0))->toBe(200.0);
});

it('inkasso routes to inkasso movement type', function (): void {
    $movement = app(CashTransactionService::class)->inkasso($this->shift, 1000.0, 'Инкассация');
    expect($movement->type)->toBe(CashMovement::TYPE_INKASSO);
});

it('blocks deposit on expired shift', function (): void {
    $this->shift->update([
        'status' => ShiftStatusEnum::EXPIRED->value,
        'opened_at' => now()->subHours(26),
    ]);

    expect(fn () => app(CashTransactionService::class)->deposit($this->shift->fresh(), 100.0, 'x'))
        ->toThrow(ShiftExpiredException::class);
});
