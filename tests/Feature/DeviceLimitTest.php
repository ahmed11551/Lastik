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

// 49.7: не более 2 активных смартфонов — третий mobile отклоняется 429.
test('user cannot register more than 2 mobile devices', function (): void {
    $fx = AcceptanceFixture::make('device-'.uniqid());
    foreach (['one', 'two'] as $fingerprint) {
        Device::query()->withoutGlobalScopes()->create([
            'tenant_id' => $fx->tenant->id,
            'user_id' => $fx->user->id,
            'device_name' => $fingerprint,
            'device_type' => Device::TYPE_MOBILE,
            'fingerprint' => $fingerprint,
            'is_active' => true,
        ]);
    }

    Route::post('/test-device-limit', fn () => response()->noContent())
        ->middleware(CheckDeviceLimit::class)
        ->name('auth.devices.register.test');

    $this->actingAs($fx->user)
        ->withHeader('User-Agent', 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148')
        ->post('/test-device-limit', ['device_type' => Device::TYPE_MOBILE])
        ->assertStatus(429);
});

test('desktop device registration is not blocked by mobile limit', function (): void {
    $fx = AcceptanceFixture::make('device-desktop-'.uniqid());
    foreach (['one', 'two'] as $fingerprint) {
        Device::query()->withoutGlobalScopes()->create([
            'tenant_id' => $fx->tenant->id,
            'user_id' => $fx->user->id,
            'device_name' => $fingerprint,
            'device_type' => Device::TYPE_MOBILE,
            'fingerprint' => $fingerprint,
            'is_active' => true,
        ]);
    }

    Route::post('/test-device-limit-desktop', fn () => response()->noContent())
        ->middleware(CheckDeviceLimit::class)
        ->name('auth.devices.register.desktop');

    $this->actingAs($fx->user)
        ->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36')
        ->post('/test-device-limit-desktop', ['device_type' => Device::TYPE_DESKTOP])
        ->assertNoContent();
});
