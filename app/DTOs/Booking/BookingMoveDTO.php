<?php

declare(strict_types=1);

namespace App\DTOs\Booking;

readonly class BookingMoveDTO
{
    public function __construct(public int $tenantId, public int $bookingId, public int $postId, public string $startTime) {}

    public static function fromRequest(int $tenantId, array $payload): self
    {
        return new self(
            tenantId: $tenantId,
            bookingId: (int) ($payload['booking_id'] ?? 0),
            postId: (int) ($payload['post_id'] ?? 0),
            startTime: (string) ($payload['start_time'] ?? ''),
        );
    }
}
