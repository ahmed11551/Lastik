<?php

/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author Себиев Ахмед Сулейманович
 * @license Proprietary & Confidential.
 */

declare(strict_types=1);

use Autometria\Models\Order;
use Autometria\Models\StorageCell;
use Autometria\Models\Tenant;
use Autometria\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;

beforeEach(function (): void {
    Config::set('app.demo_mode', false);
});

it('DemoSeeder creates demo tenant with 8 orders and storage cells', function (): void {
    Artisan::call('db:seed', ['--class' => 'DemoSeeder', '--force' => true]);

    $tenant = Tenant::query()->withoutGlobalScopes()->where('slug', 'demo')->first();
    expect($tenant)->not->toBeNull();

    $admin = User::query()->withoutGlobalScopes()
        ->where('email', 'admin@demo.local')
        ->where('tenant_id', $tenant->id)
        ->first();
    expect($admin)->not->toBeNull();

    $orders = Order::query()->withoutGlobalScopes()
        ->where('tenant_id', $tenant->id)
        ->where('number', 'like', 'DEMO-%')
        ->count();
    expect($orders)->toBe(8);

    $cells = StorageCell::query()->withoutGlobalScopes()
        ->where('tenant_id', $tenant->id)
        ->count();
    expect($cells)->toBe(4);
});

it('demo login is forbidden when demo_mode is off', function (): void {
    Artisan::call('db:seed', ['--class' => 'DemoSeeder', '--force' => true]);

    $response = $this->postJson('/api/v1/demo/login');

    $response->assertStatus(403);
});

it('demo login returns token when demo_mode is on', function (): void {
    Config::set('app.demo_mode', true);
    Artisan::call('db:seed', ['--class' => 'DemoSeeder', '--force' => true]);

    $response = $this->postJson('/api/v1/demo/login', ['email' => 'admin@demo.local']);

    $response->assertStatus(200)
        ->assertJsonStructure(['token', 'user'])
        ->assertJsonPath('user.email', 'admin@demo.local');
});
