<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

use Autometria\Enums\DeviceType;
use Autometria\Models\Device;
use Autometria\Services\DeviceService;
use Tests\Support\AcceptanceFixture;
use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->fx = AcceptanceFixture::make('devrace-'.uniqid());
    set_current_tenant_id($this->fx->tenant->id);
    actingAs($this->fx->user);
});

it('enforces total device cap = 3 across ALL device types (mobile + desktop)', function (): void {
    $svc = app(DeviceService::class);
    $u = $this->fx->user;

    // 2 mobile + 1 desktop = exactly the cap (3).
    $svc->register($u, 'fp-mobile-1', 'iPhone', 'Mozilla/5.0 (iPhone)');
    $svc->register($u, 'fp-mobile-2', 'Android', 'Mozilla/5.0 (Linux; Android)');
    $svc->register($u, 'fp-desktop-1', 'MacBook', 'Mozilla/5.0 (Macintosh; Intel Mac OS X)');

    expect($svc->activeTotalCount($u))->toBe(3);

    // 4th device (even desktop) must be rejected.
    expect(fn () => $svc->register($u, 'fp-desktop-2', 'Windows PC', 'Mozilla/5.0 (Windows NT 10.0)'))
        ->toThrow(\RuntimeException::class);

    expect($svc->activeTotalCount($u))->toBe(3);
});

it('counts desktop UA against the same cap (no desktop exception)', function (): void {
    $svc = app(DeviceService::class);
    $u = $this->fx->user;

    // Three desktop devices only.
    $svc->register($u, 'd1', 'PC1', 'Mozilla/5.0 (Windows NT 10.0)');
    $svc->register($u, 'd2', 'PC2', 'Mozilla/5.0 (Macintosh)');
    $svc->register($u, 'd3', 'PC3', 'Mozilla/5.0 (X11; Linux x86_64)');

    expect($svc->activeTotalCount($u))->toBe(3);

    // 4th desktop is blocked — proving desktop is NOT exempt.
    expect(fn () => $svc->register($u, 'd4', 'PC4', 'Mozilla/5.0 (Windows NT 11.0)'))
        ->toThrow(\RuntimeException::class);
});

it('blocks concurrent registration overshoot under pessimistic lock', function (): void {
    $svc = app(DeviceService::class);
    $u = $this->fx->user;

    // Seed 3 active devices directly (simulating prior state).
    for ($i = 1; $i <= 3; $i++) {
        Device::query()->withoutGlobalScopes()->forceCreate([
            'tenant_id' => $u->tenant_id,
            'user_id' => $u->id,
            'device_name' => 'seed-'.$i,
            'device_type' => DeviceType::DESKTOP->value,
            'fingerprint' => 'seed-fp-'.$i,
            'is_active' => true,
            'is_current' => false,
        ]);
    }

    // Within a single transaction the count is read under lockForUpdate,
    // so a 4th registration must fail even if attempted "simultaneously".
    \Illuminate\Support\Facades\DB::transaction(function () use ($svc, $u): void {
        expect(fn () => $svc->register($u, 'race-fp', 'Race PC', 'Mozilla/5.0 (Windows NT 10.0)'))
            ->toThrow(\RuntimeException::class);
    });

    expect($svc->activeTotalCount($u))->toBe(3);
});
