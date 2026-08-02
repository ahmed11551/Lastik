<?php

/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author Себиев Ахмед Сулейманович
 * @license Proprietary & Confidential.
 */

declare(strict_types=1);

use Autometria\Http\Middleware\CheckDeviceLimit;
use Autometria\Models\Device;
use Illuminate\Support\Facades\Route;
use Tests\Support\AcceptanceFixture;

// P0 (Грок review): твёрдый лимит = 3 активных устройства ВСЕГО (mobile + desktop).
// 3 устройства (любых типов) -> 4-е отклоняется 429, включая desktop.
test('middleware blocks the 4th active device of any type with 429', function (): void {
    $fx = AcceptanceFixture::make('device-'.uniqid());

    // 3 активных устройства: 2 mobile + 1 desktop.
    foreach (['m1', 'm2', 'd1'] as $fingerprint) {
        Device::query()->withoutGlobalScopes()->create([
            'tenant_id' => $fx->tenant->id,
            'user_id' => $fx->user->id,
            'device_name' => $fingerprint,
            'device_type' => str_starts_with($fingerprint, 'm') ? Device::TYPE_MOBILE : Device::TYPE_DESKTOP,
            'fingerprint' => $fingerprint,
            'is_active' => true,
        ]);
    }

    Route::post('/test-device-limit', fn () => response()->noContent())
        ->middleware(CheckDeviceLimit::class)
        ->name('auth.devices.register.test');

    // 4-е устройство (desktop UA) -> 429, потому что CAP=3 на ВСЕ типы.
    $this->actingAs($fx->user)
        ->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36')
        ->post('/test-device-limit', ['device_type' => Device::TYPE_DESKTOP])
        ->assertStatus(429);
});

test('middleware allows registration when under the cap', function (): void {
    $fx = AcceptanceFixture::make('device-ok-'.uniqid());

    // Только 2 устройства -> 3-е (в пределах капа) проходит.
    foreach (['m1', 'd1'] as $fingerprint) {
        Device::query()->withoutGlobalScopes()->create([
            'tenant_id' => $fx->tenant->id,
            'user_id' => $fx->user->id,
            'device_name' => $fingerprint,
            'device_type' => str_starts_with($fingerprint, 'm') ? Device::TYPE_MOBILE : Device::TYPE_DESKTOP,
            'fingerprint' => $fingerprint,
            'is_active' => true,
        ]);
    }

    Route::post('/test-device-limit-ok', fn () => response()->noContent())
        ->middleware(CheckDeviceLimit::class)
        ->name('auth.devices.register.ok');

    $this->actingAs($fx->user)
        ->withHeader('User-Agent', 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148')
        ->post('/test-device-limit-ok', ['device_type' => Device::TYPE_MOBILE])
        ->assertNoContent();
});
