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

// 49.7: пользователь не может зарегистрировать более 2 устройств — третий отклоняется 409.
test('user cannot register more than 2 devices', function (): void {
    $fx = AcceptanceFixture::make('device-'.uniqid());
    foreach (['one', 'two'] as $fingerprint) {
        Device::query()->withoutGlobalScopes()->create([
            'tenant_id' => $fx->tenant->id, 'user_id' => $fx->user->id,
            'device_name' => $fingerprint, 'device_type' => 'mobile', 'fingerprint' => $fingerprint,
            'is_active' => true,
        ]);
    }

    Route::post('/test-device-limit', fn () => response()->noContent())
        ->middleware(CheckDeviceLimit::class)
        ->name('auth.devices.register.test');

    $this->actingAs($fx->user)->post('/test-device-limit')->assertStatus(429);
});
