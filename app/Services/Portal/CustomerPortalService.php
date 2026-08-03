<?php

declare(strict_types=1);

namespace Autometria\Services\Portal;

use Autometria\Models\Booking;
use Autometria\Models\Customer;
use Autometria\Models\CustomerPortalToken;
use Autometria\Models\Post;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class CustomerPortalService
{
    /**
     * @return array{plain: string, token: CustomerPortalToken}
     */
    public function issueToken(int $tenantId, int $customerId): array
    {
        set_current_tenant_id($tenantId);

        Customer::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->findOrFail($customerId);

        $plain = bin2hex(random_bytes(32));
        $token = CustomerPortalToken::query()->withoutGlobalScopes()->forceCreate([
            'tenant_id' => $tenantId,
            'customer_id' => $customerId,
            'token' => $this->hashToken($plain),
            'expires_at' => now()->addDays(30),
        ]);

        return compact('plain', 'token');
    }

    public function resolveToken(string $plain): ?Customer
    {
        $token = CustomerPortalToken::query()->withoutGlobalScopes()
            ->where('token', $this->hashToken($plain))
            ->where('expires_at', '>', now())
            ->first();

        if ($token === null) {
            return null;
        }

        return Customer::query()->withoutGlobalScopes()
            ->where('tenant_id', $token->tenant_id)
            ->find($token->customer_id);
    }

    public function bookSlot(int $tenantId, int $customerId, int $postId, string $start, string $end): Booking
    {
        $startAt = CarbonImmutable::parse($start);
        $endAt = CarbonImmutable::parse($end);

        if ($endAt->lessThanOrEqualTo($startAt)) {
            throw new \InvalidArgumentException('Booking end time must be after start time.');
        }

        return DB::transaction(function () use ($tenantId, $customerId, $postId, $startAt, $endAt): Booking {
            set_current_tenant_id($tenantId);

            $customer = Customer::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->findOrFail($customerId);

            $post = Post::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->lockForUpdate()
                ->findOrFail($postId);

            $overlaps = Booking::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('post_id', $post->id)
                ->where('status', '!=', Booking::CANCELLED)
                ->where('start_time', '<', $endAt)
                ->where('end_time', '>', $startAt)
                ->lockForUpdate()
                ->exists();

            if ($overlaps) {
                throw new ConflictHttpException('This time slot is no longer available.');
            }

            return Booking::query()->withoutGlobalScopes()->forceCreate([
                'tenant_id' => $tenantId,
                'post_id' => $post->id,
                'customer_id' => $customer->id,
                'customer_name' => $customer->name,
                'customer_phone' => $customer->phone,
                'start_time' => $startAt,
                'end_time' => $endAt,
                'status' => Booking::PENDING,
            ]);
        });
    }

    public function cancelBooking(int $tenantId, int $customerId, int $bookingId): Booking
    {
        set_current_tenant_id($tenantId);

        $booking = Booking::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('customer_id', $customerId)
            ->findOrFail($bookingId);

        $booking->status = Booking::CANCELLED;
        $booking->save();

        return $booking;
    }

    /**
     * @return Collection<int, Booking>
     */
    public function myBookings(int $tenantId, int $customerId): Collection
    {
        set_current_tenant_id($tenantId);

        return Booking::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('customer_id', $customerId)
            ->with('post:id,name')
            ->orderByDesc('start_time')
            ->get();
    }

    private function hashToken(string $plain): string
    {
        return hash('sha256', $plain);
    }
}
