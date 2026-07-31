<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\Booking\BookingMoveDTO;
use App\Exceptions\Domain\SlotAlreadyBookedException;
use App\Models\Booking;
use App\Models\Post;
use Illuminate\Support\Facades\DB;

class MoveBookingService
{
    public function move(BookingMoveDTO $dto): Booking
    {
        return DB::transaction(function () use ($dto): Booking {
            $original = Booking::query()
                ->where('tenant_id', $dto->tenantId)
                ->lockForUpdate()
                ->findOrFail($dto->bookingId);

            $originalDuration = $original->end_time->getTimestamp() - $original->start_time->getTimestamp();
            $newStart = new \DateTimeImmutable($dto->startTime);
            $newEnd = (new \DateTimeImmutable($dto->startTime))->modify("+{$originalDuration} seconds");

            $post = Post::query()
                ->where('tenant_id', $dto->tenantId)
                ->where('id', $dto->postId)
                ->firstOrFail();

            $overlap = Booking::query()
                ->where('tenant_id', $dto->tenantId)
                ->where('post_id', $post->id)
                ->where('status', 'booked')
                ->where('id', '!=', $original->id)
                ->where(function ($q) use ($newStart, $newEnd) {
                    $q->whereBetween('start_time', [$newStart->format('Y-m-d H:i:s'), $newEnd->format('Y-m-d H:i:s')])
                        ->orWhereBetween('end_time', [$newStart->format('Y-m-d H:i:s'), $newEnd->format('Y-m-d H:i:s')])
                        ->orWhere(function ($q2) use ($newStart, $newEnd) {
                            $q2->where('start_time', '<=', $newStart->format('Y-m-d H:i:s'))
                                ->where('end_time', '>=', $newEnd->format('Y-m-d H:i:s'));
                        });
                })
                ->lockForUpdate()
                ->exists();

            if ($overlap) {
                throw SlotAlreadyBookedException::default();
            }

            $original->update([
                'post_id' => $post->id,
                'start_time' => $newStart->format('Y-m-d H:i:s'),
                'end_time' => $newEnd->format('Y-m-d H:i:s'),
            ]);

            return $original->refresh();
        });
    }
}
