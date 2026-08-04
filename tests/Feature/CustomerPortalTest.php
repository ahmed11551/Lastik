<?php

declare(strict_types=1);

use Autometria\Models\Booking;
use Autometria\Models\Customer;
use Autometria\Models\Post;
use Autometria\Services\Portal\CustomerPortalService;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\Support\AcceptanceFixture;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

beforeEach(function (): void {
    config(['cache.default' => 'array']);
    \Illuminate\Support\Facades\Cache::clear();
    \Illuminate\Support\Facades\RateLimiter::clear('auth-api');
    $this->fx = AcceptanceFixture::make('portal-'.uniqid());
    set_current_tenant_id($this->fx->tenant->id);
    $this->post = Post::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $this->fx->tenant->id,
        'name' => 'Пост клиентского портала',
        'is_active' => true,
    ]);
    $this->portal = app(CustomerPortalService::class);
});

it('issues and resolves a customer portal token', function (): void {
    $issued = $this->portal->issueToken($this->fx->tenant->id, $this->fx->customer->id);

    expect($issued['plain'])->not->toBeEmpty();
    expect($issued['token']->token)->not->toBe($issued['plain']);
    expect($issued['token']->expires_at->isSameDay(now()->addDays(30)))->toBeTrue();
    expect($this->portal->resolveToken($issued['plain'])?->id)->toBe($this->fx->customer->id);
});

it('creates a pending booking and rejects overlapping slots', function (): void {
    $booking = $this->portal->bookSlot(
        $this->fx->tenant->id,
        $this->fx->customer->id,
        $this->post->id,
        '2026-08-10 10:00:00',
        '2026-08-10 10:30:00',
    );

    expect($booking->status)->toBe(Booking::PENDING);
    expect($booking->customer_id)->toBe($this->fx->customer->id);
    expect(fn () => $this->portal->bookSlot(
        $this->fx->tenant->id,
        $this->fx->customer->id,
        $this->post->id,
        '2026-08-10 10:15:00',
        '2026-08-10 10:45:00',
    ))->toThrow(ConflictHttpException::class);
});

it('serves portal API data and scopes bookings to the token customer', function (): void {
    $other = Customer::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $this->fx->tenant->id,
        'type' => Customer::TYPE_INDIVIDUAL,
        'name' => 'Другой клиент',
        'phone' => '+79990001122',
        'email' => 'other-'.uniqid().'@example.test',
    ]);
    $own = $this->portal->bookSlot(
        $this->fx->tenant->id, $this->fx->customer->id, $this->post->id,
        '2026-08-11 10:00:00', '2026-08-11 10:30:00',
    );
    $otherBooking = $this->portal->bookSlot(
        $this->fx->tenant->id, $other->id, $this->post->id,
        '2026-08-11 11:00:00', '2026-08-11 11:30:00',
    );

    $login = postJson('/api/v1/portal/auth/request-token', [
        'tenant_id' => $this->fx->tenant->id,
        'phone' => $this->fx->customer->phone,
    ])->assertOk();
    $token = $login->json('token');

    getJson('/api/v1/portal/me', ['Authorization' => 'Bearer '.$token])
        ->assertOk()
        ->assertJsonPath('data.id', $this->fx->customer->id);
    getJson('/api/v1/portal/bookings', ['Authorization' => 'Bearer '.$token])
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $own->id);
    getJson('/api/v1/portal/posts', ['Authorization' => 'Bearer '.$token])
        ->assertOk()
        ->assertJsonPath('data.0.id', $this->post->id);

    deleteJson('/api/v1/portal/bookings/'.$otherBooking->id, [], ['Authorization' => 'Bearer '.$token])
        ->assertNotFound();
    deleteJson('/api/v1/portal/bookings/'.$own->id, [], ['X-Portal-Token' => $token])
        ->assertOk()
        ->assertJsonPath('data.status', Booking::CANCELLED);
});
