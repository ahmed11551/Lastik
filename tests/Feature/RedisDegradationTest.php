<?php

/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author Себиев Ахмед Сулейманович
 * @license Proprietary & Confidential.
 */

declare(strict_types=1);

use Autometria\Models\Order;
use Autometria\Models\Tenant;
use Autometria\Services\Analytics\AnalyticsCacheService;
use Autometria\Services\TvBoardService;
use Illuminate\Support\Facades\Cache;

/**
 * TD-3: Graceful Redis degradation.
 * При недоступности Redis сервисы ТВ-борды и аналитики НЕ бросают 500,
 * а отдают актуальные данные прямо из БД (fallback) и пишут Log::warning.
 */
beforeEach(function (): void {
    config(['cache.default' => 'redis']);
    Cache::spy();
});

it('tv board falls back to DB query when redis read throws', function (): void {
    $tenant = Tenant::create(['name' => 'REDIS-A'.uniqid(), 'slug' => 'redis-a-'.uniqid()]);
    set_current_tenant_id($tenant->id);

    // Создаём заказ, который попадёт на борд (status CREATED).
    Order::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $tenant->id,
        'status' => Order::STATUS_CREATED,
        'number' => 'R-'.uniqid(),
        'scenario' => 'with_installation',
    ]);

    // Redis read падает.
    Cache::shouldReceive('get')->andThrow(new RuntimeException('Redis connection refused'));

    $service = new TvBoardService();
    $board = $service->board($tenant->id, null);

    expect($board)->toBeArray();
    expect($board['columns']['queue'])->toBeArray();
});

it('tv board falls back to DB when redis write throws after successful read-miss', function (): void {
    $tenant = Tenant::create(['name' => 'REDIS-B'.uniqid(), 'slug' => 'redis-b-'.uniqid()]);
    set_current_tenant_id($tenant->id);

    Order::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $tenant->id,
        'status' => Order::STATUS_IN_PROGRESS,
        'number' => 'R-'.uniqid(),
    ]);

    // get возвращает null (miss), put бросает — fallback всё равно отдаёт данные.
    Cache::shouldReceive('get')->andReturn(null);
    Cache::shouldReceive('put')->andThrow(new RuntimeException('Redis write refused'));

    $service = new TvBoardService();
    $board = $service->board($tenant->id, null);

    expect($board['columns']['in_progress'])->toBeArray();
});

it('analytics dashboard falls back to DB when redis throws', function (): void {
    $tenant = Tenant::create(['name' => 'REDIS-C'.uniqid(), 'slug' => 'redis-c-'.uniqid()]);
    set_current_tenant_id($tenant->id);

    Cache::shouldReceive('get')->andThrow(new RuntimeException('Redis down'));
    Cache::shouldReceive('forever')->andThrow(new RuntimeException('Redis down'));

    $service = new AnalyticsCacheService(new \Autometria\Services\Analytics\AnalyticsReportService());
    $summary = $service->getDashboardSummary($tenant->id, null, null, null);

    expect($summary)->toBeArray();
});

it('cache invalidation is best-effort and never throws on redis failure', function (): void {
    $tenant = Tenant::create(['name' => 'REDIS-D'.uniqid(), 'slug' => 'redis-d-'.uniqid()]);

    Cache::shouldReceive('forget')->andThrow(new RuntimeException('Redis down'));

    $service = new TvBoardService();
    // Не должно бросить исключение.
    expect(fn () => $service->forget($tenant->id, null))->not->toThrow(Throwable::class);
});
