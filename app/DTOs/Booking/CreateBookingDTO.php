<?php

declare(strict_types=1);

namespace App\DTOs\Booking;

readonly class CreateBookingDTO
{
    public function __construct(
        public int $tenantId,
        public int $postId,
        public string $customerName,
        public string $customerPhone,
        public string $startTime,
        public string $endTime,
    ) {}

    public static function fromRequest(array $payload): self
    {
        return new self(
            tenantId: (int) ($payload['tenant_id'] ?? 0),
            postId: (int) ($payload['post_id'] ?? 0),
            customerName: (string) ($payload['customer_name'] ?? ''),
            customerPhone: (string) ($payload['customer_phone'] ?? ''),
            startTime: (string) ($payload['start_time'] ?? ''),
            endTime: (string) ($payload['end_time'] ?? ''),
        );
    }
}
