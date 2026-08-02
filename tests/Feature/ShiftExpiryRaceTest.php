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
use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->fx = AcceptanceFixture::make('shiftrc-'.uniqid());
    set_current_tenant_id($this->fx->tenant->id);
    actingAs($this->fx->user);
});

it('requireActiveLocked passes while expires_at is in the future (DB clock)', function (): void {
    $svc = app(CashShiftService::class);
    $t = $this->fx->tenant->id;

    $shift = CashShift::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $t,
        'location_id' => $this->fx->location->id,
        'user_id' => $this->fx->user->id,
        'opened_by' => $this->fx->user->id,
        'status' => ShiftStatusEnum::OPENED->value,
        'opening_amount' => 0,
        'opened_at' => now(),
        'expires_at' => now()->addMinutes(30), // clearly in the future
        'closed_at' => null,
    ]);

    $locked = $svc->requireActiveLocked((int) $shift->id, $t);
    expect($locked->id)->toBe($shift->id);
});

it('requireActiveLocked throws when expires_at is in the past (DB clock)', function (): void {
    $svc = app(CashShiftService::class);
    $t = $this->fx->tenant->id;

    $shift = CashShift::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $t,
        'location_id' => $this->fx->location->id,
        'user_id' => $this->fx->user->id,
        'opened_by' => $this->fx->user->id,
        'status' => ShiftStatusEnum::OPENED->value,
        'opening_amount' => 0,
        'opened_at' => now()->subHours(25), // older than 24h
        'expires_at' => now()->subHour(),    // already expired per DB clock
        'closed_at' => null,
    ]);

    // SQL predicate `expires_at > clock_timestamp()` rejects it -> ShiftExpiredException.
    expect(fn () => $svc->requireActiveLocked((int) $shift->id, $t))
        ->toThrow(ShiftExpiredException::class);

    // And the shift is auto-transitioned to EXPIRED.
    $shift->refresh();
    expect($shift->status)->toBe(ShiftStatusEnum::EXPIRED->value);
});

it('a deposit at the exact expiry boundary is rejected by the SQL guard', function (): void {
    $svc = app(CashShiftService::class);
    $t = $this->fx->tenant->id;

    $shift = CashShift::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $t,
        'location_id' => $this->fx->location->id,
        'user_id' => $this->fx->user->id,
        'opened_by' => $this->fx->user->id,
        'status' => ShiftStatusEnum::OPENED->value,
        'opening_amount' => 100,
        'opened_at' => now()->subHours(24)->subSeconds(2),
        'expires_at' => now()->subSeconds(1), // just expired
        'closed_at' => null,
    ]);

    // The deposit must NOT go through: requireActiveLocked inside the tx fails.
    expect(fn () => $svc->deposit($shift, 50.0, 'пополнение'))
        ->toThrow(ShiftExpiredException::class);
});
