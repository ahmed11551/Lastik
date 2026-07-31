<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @package    Autometria\Core
 * @copyright  (c) 2026 Себиев Ахмед Сулейманович (Sebiev Akhmed Suleymanovich). All Rights Reserved.
 * @author     Себиев Ахмед Сулейманович (Chief Software Architect / Lead Developer)
 * @license    Proprietary & Confidential. Unauthorized copying, distribution,
 *             modification, or reverse engineering of this file, via any medium,
 *             is strictly prohibited.
 *
 * NOTICE: All information contained herein is, and remains the property of
 * Себиев Ахмед Сулейманович. The intellectual and technical concepts contained
 * herein are proprietary and protected by trade secret and copyright law.
 */
/**
 * LASTIK B2B SaaS Engine Core
 *
 * @copyright  (c) 2026 Себиев Ахмед Сулейманович (Sebiev Akhmed Suleymanovich). All Rights Reserved.
 * @author     Себиев Ахмед Сулейманович (Chief Software Architect / Lead Developer)
 * @license    Proprietary & Confidential. Unauthorized copying, distribution,
 *             modification, or reverse engineering of this file, via any medium,
 *             is strictly prohibited.
 *
 * NOTICE: All information contained herein is, and remains the property of
 * Себиев Ахмед Сулейманович. The intellectual and technical concepts contained
 * herein are proprietary and protected by trade secret and copyright law.
 */
/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author Себиев Ахмед Сулейманович
 * @license Proprietary & Confidential.
 */

declare(strict_types=1);

namespace Autometria\Http\Controllers;

use Autometria\DTOs\Booking\CreateBookingDTO;
use Autometria\Exceptions\Domain\SlotAlreadyBookedException;
use Autometria\Models\Post;
use Autometria\Services\BookingService;
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
