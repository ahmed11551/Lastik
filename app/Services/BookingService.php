<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\Booking\CreateBookingDTO;
use App\Exceptions\Domain\SlotAlreadyBookedException;
use App\Models\Booking;
use App\Models\Post;
use Illuminate\Support\Facades\DB;

class BookingService
{
    public function getAvailableSlots(int $postId, string $date, int $slotMinutes = 30, string $from = '09:00', string $to = '18:00'): array
    {
        $post = Post::query()->findOrFail($postId);
        $dayStart = "{$date} {$from}:00";
        $dayEnd = "{$date} {$to}:00";

        $booked = Booking::query()
            ->where('post_id', $postId)
            ->where('status', 'booked')
            ->where(function ($q) use ($dayStart, $dayEnd) {
                $q->whereBetween('start_time', [$dayStart, $dayEnd])
                    ->orWhereBetween('end_time', [$dayStart, $dayEnd])
                    ->orWhere(function ($q2) use ($dayStart, $dayEnd) {
                        $q2->where('start_time', '<=', $dayStart)->where('end_time', '>=', $dayEnd);
                    });
            })
            ->orderBy('start_time')
            ->get(['start_time', 'end_time'])
            ->all();

        $bookedRanges = array_map(static fn ($b) => [$b->start_time, $b->end_time], $booked);
        $slots = [];
        $cursor = $dayStart;

        while ($cursor < $dayEnd) {
            $slotStart = $cursor;
            $slotEnd = (new \DateTime($cursor))->modify("+{$slotMinutes} minutes")->format('Y-m-d H:i:s');

            $overlap = false;
            foreach ($bookedRanges as [$s, $e]) {
                if ($slotStart < $e && $slotEnd > $s) {
                    $overlap = true;
                    break;
                }
            }

            if (! $overlap) {
                $slots[] = ['start' => $slotStart, 'end' => $slotEnd];
            }

            $cursor = $slotEnd;
        }

        return $slots;
    }

    public function createBooking(CreateBookingDTO $dto): Booking
    {
        return DB::transaction(function () use ($dto): Booking {
            $post = Post::query()
                ->where('tenant_id', $dto->tenantId)
                ->where('id', $dto->postId)
                ->lockForUpdate()
                ->firstOrFail();

            $overlap = Booking::query()
                ->where('tenant_id', $dto->tenantId)
                ->where('post_id', $post->id)
                ->where('status', 'booked')
                ->where(function ($q) use ($dto) {
                    $q->whereBetween('start_time', [$dto->startTime, $dto->endTime])
                        ->orWhereBetween('end_time', [$dto->startTime, $dto->endTime])
                        ->orWhere(function ($q2) use ($dto) {
                            $q2->where('start_time', '<=', $dto->startTime)->where('end_time', '>=', $dto->endTime);
                        });
                })
                ->lockForUpdate()
                ->exists();

            if ($overlap) {
                throw SlotAlreadyBookedException::default();
            }

            return Booking::create([
                'tenant_id' => $dto->tenantId,
                'post_id' => $post->id,
                'customer_name' => $dto->customerName,
                'customer_phone' => $dto->customerPhone,
                'start_time' => $dto->startTime,
                'end_time' => $dto->endTime,
                'status' => 'booked',
            ]);
        });
    }
}
