<?php

declare(strict_types=1);

use App\DTOs\Booking\CreateBookingDTO;
use App\Exceptions\Domain\SlotAlreadyBookedException;
use App\Models\Booking;
use App\Models\Post;
use App\Services\BookingService;
use Tests\Support\AcceptanceFixture;

it('creates booking and prevents overlap within same tenant', function (): void {
    $fx = AcceptanceFixture::make('booking-'.uniqid());
    $post = Post::query()->withoutGlobalScopes()->create(['tenant_id' => $fx->tenant->id, 'name' => 'Подъемник 1', 'is_active' => true]);
    $dto = new CreateBookingDTO($fx->tenant->id, $post->id, 'Клиент', '+7999000000', '2026-08-02 10:00:00', '2026-08-02 10:30:00');
    $booking = app(BookingService::class)->createBooking($dto);

    expect($booking->status)->toBe('booked');
    expect(fn () => app(BookingService::class)->createBooking(
        new CreateBookingDTO($fx->tenant->id, $post->id, 'Клиент 2', '+7999000001', '2026-08-02 10:15:00', '2026-08-02 10:45:00')
    ))->toThrow(SlotAlreadyBookedException::class);
});

it('keeps bookings isolated between tenants', function (): void {
    $a = AcceptanceFixture::make('booking-a-'.uniqid());
    $b = AcceptanceFixture::make('booking-b-'.uniqid());
    $postA = Post::query()->withoutGlobalScopes()->create(['tenant_id' => $a->tenant->id, 'name' => 'A', 'is_active' => true]);
    $postB = Post::query()->withoutGlobalScopes()->create(['tenant_id' => $b->tenant->id, 'name' => 'B', 'is_active' => true]);
    $service = app(BookingService::class);
    app()->instance('current_tenant_id', $a->tenant->id);
    $service->createBooking(new CreateBookingDTO($a->tenant->id, $postA->id, 'A', '+1', '2026-08-02 11:00:00', '2026-08-02 11:30:00'));
    app()->instance('current_tenant_id', $b->tenant->id);
    $service->createBooking(new CreateBookingDTO($b->tenant->id, $postB->id, 'B', '+2', '2026-08-02 11:00:00', '2026-08-02 11:30:00'));

    expect(Booking::query()->withoutGlobalScopes()->where('tenant_id', $a->tenant->id)->count())->toBe(1);
    expect(Booking::query()->withoutGlobalScopes()->where('tenant_id', $b->tenant->id)->count())->toBe(1);
});
