<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Post;
use Illuminate\Http\Request;
use Inertia\Inertia;

class BookingPageController extends Controller
{
    public function index(Request $request)
    {
        $tenantId = (int) ($request->user()?->tenant_id ?? 0);

        return Inertia::render('Bookings/PublicBooking', [
            'posts' => Post::query()->where('tenant_id', $tenantId)->where('is_active', true)->get(['id', 'name']),
            'bookings' => Booking::query()->where('tenant_id', $tenantId)->get(['post_id', 'start_time', 'end_time', 'customer_name', 'customer_phone', 'status']),
        ]);
    }
}
