<?php

declare(strict_types=1);

use App\Http\Middleware\CheckDeviceLimit;
use App\Models\Device;
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
