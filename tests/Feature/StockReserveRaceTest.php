<?php

declare(strict_types=1);

use App\Exceptions\Domain\InsufficientStockException;
use App\Services\StockReservationService;
use Tests\Support\AcceptanceFixture;

test('second reservation of the last unit fails without negative availability', function (): void {
    $fx = AcceptanceFixture::make('reserve-race-'.uniqid());
    $fx->stock->update(['actual' => 1, 'reserved' => 0, 'available' => 1]);
    $service = app(StockReservationService::class);
    $service->reserve($fx->stock->id, $fx->tenant->id, 1);
    expect(fn () => $service->reserve($fx->stock->id, $fx->tenant->id, 1))->toThrow(InsufficientStockException::class);
    $fx->stock->refresh();
    expect((float) $fx->stock->available)->toBe(0.0)->and((float) $fx->stock->reserved)->toBe(1.0);
});
