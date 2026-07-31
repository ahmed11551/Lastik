<?php

declare(strict_types=1);

use App\DTOs\CreateOrderDTO;
use App\Models\Order;
use App\Services\OrderService;
use App\Services\TvBoardService;
use Tests\Support\AcceptanceFixture;

/**
 * Приёмка п.42 — TV-табло текущих работ.
 */
beforeEach(function (): void {
    $this->fx = AcceptanceFixture::make('tv-'.uniqid());
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

    $board = app(TvBoardService::class)->board($fx->tenant->id, $fx->location->id);

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
