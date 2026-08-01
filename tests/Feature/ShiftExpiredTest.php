<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

use Autometria\Enums\ShiftStatusEnum;
use Autometria\Exceptions\Domain\ShiftExpiredException;
use Autometria\Models\CashShift;
use Autometria\Services\Cash\CashShiftService;
use Tests\Support\AcceptanceFixture;

beforeEach(function (): void {
    $this->fx = AcceptanceFixture::make('shift-exp-'.uniqid());
    set_current_tenant_id($this->fx->tenant->id);
});

it('auto-expires a shift older than 24h and blocks operations', function (): void {
    $shift = CashShift::query()->withoutGlobalScopes()->create([
        'tenant_id' => $this->fx->tenant->id,
        'location_id' => $this->fx->location->id,
        'user_id' => $this->fx->user->id,
        'opened_by' => $this->fx->user->id,
        'status' => ShiftStatusEnum::OPENED->value,
        'opening_amount' => 0,
        'opened_at' => now()->subHours(25),
        'expires_at' => now()->subHour(),
        'closed_at' => null,
    ]);

    expect($shift->status)->toBe(ShiftStatusEnum::OPENED->value);

    // assertShiftActive should transition to EXPIRED and throw
    expect(fn () => app(CashShiftService::class)->assertShiftActive($shift->fresh()))
        ->toThrow(ShiftExpiredException::class);

    $shift->refresh();
    expect($shift->status)->toBe(ShiftStatusEnum::EXPIRED->value);
});

it('passes assertShiftActive for a fresh shift', function (): void {
    $shift = CashShift::query()->withoutGlobalScopes()->create([
        'tenant_id' => $this->fx->tenant->id,
        'location_id' => $this->fx->location->id,
        'user_id' => $this->fx->user->id,
        'opened_by' => $this->fx->user->id,
        'status' => ShiftStatusEnum::OPENED->value,
        'opening_amount' => 0,
        'opened_at' => now(),
        'expires_at' => now()->addHours(24),
        'closed_at' => null,
    ]);

    expect(fn () => app(CashShiftService::class)->assertShiftActive($shift->fresh()))
        ->not->toThrow(ShiftExpiredException::class);
});

it('expireOverdueShifts transitions all stale shifts', function (): void {
    foreach (range(1, 3) as $i) {
        CashShift::query()->withoutGlobalScopes()->create([
            'tenant_id' => $this->fx->tenant->id,
            'location_id' => $this->fx->location->id,
            'user_id' => $this->fx->user->id,
            'opened_by' => $this->fx->user->id,
            'status' => ShiftStatusEnum::OPENED->value,
            'opening_amount' => 0,
            'opened_at' => now()->subHours(30),
            'closed_at' => null,
        ]);
    }

    $expired = app(CashShiftService::class)->expireOverdueShifts();
    expect($expired)->toBe(3);

    $stillStale = CashShift::query()->withoutGlobalScopes()
        ->where('tenant_id', $this->fx->tenant->id)
        ->where('status', ShiftStatusEnum::OPENED->value)
        ->whereNull('closed_at')
        ->where('opened_at', '<=', now()->subHours(24))
        ->count();
    expect($stillStale)->toBe(0);
});
