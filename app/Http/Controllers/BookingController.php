<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DTOs\Booking\CreateBookingDTO;
use App\Exceptions\Domain\SlotAlreadyBookedException;
use App\Models\Post;
use App\Services\BookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    public function __construct(private readonly BookingService $bookings) {}

    public function index(Request $request)
    {
        return Inertia::render('Bookings/Index', [
            'posts' => Post::query()->where('tenant_id', $request->user()?->tenant_id ?? 0)->get(['id', 'name', 'is_active']),
        ]);
    }

    public function slots(Request $request, BookingService $bookingService, int $postId): JsonResponse
    {
        $request->validate([
            'date' => ['required', 'date'],
        ]);

        $slots = $bookingService->getAvailableSlots($postId, $request->input('date'));

        return response()->json(['slots' => $slots]);
    }

    public function store(Request $request, BookingService $bookingService): JsonResponse
    {
        $request->validate([
            'post_id' => ['required', 'integer', 'exists:posts,id'],
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_phone' => ['required', 'string', 'max:255'],
            'start_time' => ['required', 'date'],
            'end_time' => ['required', 'date', 'after:start_time'],
        ]);

        $dto = CreateBookingDTO::fromRequest(
            $request->only(['post_id', 'customer_name', 'customer_phone', 'start_time', 'end_time']),
            (int) ($request->user()?->tenant_id ?? tenant_id() ?? 0),
        );

        try {
            $booking = $bookingService->createBooking($dto);
        } catch (SlotAlreadyBookedException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['booking' => $booking], 201);
    }
}
