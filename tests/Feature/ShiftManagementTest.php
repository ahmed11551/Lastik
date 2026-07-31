<?php

declare(strict_types=1);

use App\Exceptions\Domain\ShiftAlreadyClosedException;
use App\Services\Cash\CashShiftService;
use Tests\Support\AcceptanceFixture;

it('returns the existing open shift and closes it with totals', function (): void {
    $fx = AcceptanceFixture::make('shift-management-'.uniqid());
    $service = app(CashShiftService::class);
    $opened = $service->open($fx->tenant->id, $fx->location->id, $fx->user->id);
    expect($opened->id)->toBe($fx->shift->id);

    $closed = $service->close($fx->shift);
    expect($closed->status)->toBe('closed')->and($closed->closed_at)->not->toBeNull();
});

it('opens a replacement shift after the prior one closes', function (): void {
    $fx = AcceptanceFixture::make('shift-reopen-'.uniqid());
    $service = app(CashShiftService::class);
    $service->close($fx->shift);
    $replacement = $service->open($fx->tenant->id, $fx->location->id, $fx->user->id);
    expect($replacement->id)->not->toBe($fx->shift->id)->and($replacement->status)->toBe('opened');
});

it('rejects a second close with ShiftAlreadyClosedException', function (): void {
    $fx = AcceptanceFixture::make('shift-twice-'.uniqid());
    $service = app(CashShiftService::class);
    $service->close($fx->shift);

    expect(fn () => $service->close($fx->shift->fresh()))
        ->toThrow(ShiftAlreadyClosedException::class);
});
