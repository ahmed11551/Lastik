<?php

/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author Себиев Ахмед Сулейманович
 * @license Proprietary & Confidential.
 */

declare(strict_types=1);

use Autometria\DTOs\CreateOrderDTO;
use Autometria\Models\Order;
use Autometria\Services\OrderService;
use Autometria\Services\TvBoardService;
use Illuminate\Support\Facades\Cache;
use Tests\Support\AcceptanceFixture;

/**
 * Приёмка п.42 — TV-табло текущих работ.
 */
beforeEach(function (): void {
    $this->fx = AcceptanceFixture::make('tv-'.uniqid());
    config(['cache.default' => 'array']);
});

it('groups installation orders into queue / in_progress / ready columns', function (): void {
    $fx = $this->fx;
    $svc = app(OrderService::class);

    $make = function (string $scenario) use ($fx, $svc): Order {
        return $svc->create(new CreateOrderDTO(
            tenantId: $fx->tenant->id,
            customerId: $fx->customer->id,
            locationId: $fx->location->id,
            assignedSellerId: $fx->user->id,
            masterId: $fx->master->id,
            items: [[
                'type' => 'service',
                'product_id' => $fx->service->id,
                'qty' => 1,
                'price' => 1200,
            ]],
            scenario: $scenario,
            vehicleId: $fx->vehicle->id,
        ), $fx->user->id);
    };

    $queue = $make('with_installation');
    $progress = $make('with_installation');
    $ready = $make('with_installation');
    $skip = $make('without_installation');

    Order::query()->withoutGlobalScopes()->whereKey($progress->id)->update(['status' => Order::STATUS_IN_PROGRESS]);
    Order::query()->withoutGlobalScopes()->whereKey($ready->id)->update(['status' => Order::STATUS_READY]);

    $tv = app(TvBoardService::class);
    $tv->forget($fx->tenant->id, $fx->location->id);

    $board = $tv->board($fx->tenant->id, $fx->location->id);

    $queueIds = collect($board['columns']['queue'])->pluck('id')->all();
    $progressIds = collect($board['columns']['in_progress'])->pluck('id')->all();
    $readyIds = collect($board['columns']['ready'])->pluck('id')->all();

    expect($queueIds)->toContain($queue->id);
    expect($progressIds)->toContain($progress->id);
    expect($readyIds)->toContain($ready->id);
    expect($queueIds)->not->toContain($skip->id);
    expect($progressIds)->not->toContain($skip->id);
    expect($readyIds)->not->toContain($skip->id);

    expect($board['columns']['queue'][0]['plate'] ?? null)->not->toBeEmpty();
});

it('tv board service hits cache with five seconds ttl', function (): void {
    Cache::flush();
    Cache::spy();

    $fx = $this->fx;
    $tv = app(TvBoardService::class);
    $expectedKey = sprintf('tv_board:%d:%d', $fx->tenant->id, $fx->location->id);

    $tv->board($fx->tenant->id, $fx->location->id);

    // safeRemember: читает ключ (miss → null), затем пишет с TTL 5с.
    Cache::shouldHaveReceived('get')
        ->once()
        ->with($expectedKey);
    Cache::shouldHaveReceived('put')
        ->once()
        ->with($expectedKey, \Mockery::any(), TvBoardService::CACHE_TTL_SECONDS);
});
