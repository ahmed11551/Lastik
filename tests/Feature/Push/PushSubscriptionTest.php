<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

use Autometria\Models\User;
use Autometria\Notifications\AutometriaWebPushNotification;
use Illuminate\Support\Facades\Notification;
use NotificationChannels\WebPush\PushSubscription;
use Tests\Support\AcceptanceFixture;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

beforeEach(function (): void {
    $this->fx = AcceptanceFixture::make('push-'.uniqid());
    set_current_tenant_id($this->fx->tenant->id);
    actingAs($this->fx->user);
});

it('exposes VAPID public key configuration flag', function (): void {
    config(['webpush.vapid.public_key' => 'BTEST_PUBLIC_KEY_FOR_UNIT']);

    $res = getJson('/api/v1/push/vapid-public-key');
    $res->assertOk()
        ->assertJsonPath('data.public_key', 'BTEST_PUBLIC_KEY_FOR_UNIT')
        ->assertJsonPath('data.configured', true);
});

it('stores and deletes a push subscription for the authenticated user', function (): void {
    $endpoint = 'https://fcm.googleapis.com/fcm/send/autometria-test-'.uniqid();

    $store = postJson('/api/v1/push-subscriptions', [
        'endpoint' => $endpoint,
        'keys' => [
            'p256dh' => 'BK_test_p256dh_key_value_base64url',
            'auth' => 'auth_token_test_value',
        ],
        'content_encoding' => 'aesgcm',
    ]);

    $store->assertCreated()
        ->assertJsonPath('data.endpoint', $endpoint);

    expect(
        PushSubscription::query()
            ->where('endpoint', $endpoint)
            ->where('subscribable_type', User::class)
            ->where('subscribable_id', $this->fx->user->id)
            ->exists()
    )->toBeTrue();

    $del = deleteJson('/api/v1/push-subscriptions', [
        'endpoint' => $endpoint,
    ]);
    $del->assertOk()->assertJsonPath('data.deleted', true);

    expect(PushSubscription::query()->where('endpoint', $endpoint)->exists())->toBeFalse();
});

it('rejects test push when user has no subscriptions', function (): void {
    postJson('/api/v1/push-subscriptions/test', [
        'title' => 'Test',
        'body' => 'Should fail',
    ])->assertStatus(422);
});

it('queues AutometriaWebPushNotification via WebPush channel', function (): void {
    Notification::fake();

    $endpoint = 'https://fcm.googleapis.com/fcm/send/autometria-notify-'.uniqid();
    $this->fx->user->updatePushSubscription(
        $endpoint,
        'BK_test_p256dh_key_value_base64url',
        'auth_token_test_value',
        'aesgcm',
    );

    postJson('/api/v1/push-subscriptions/test', [
        'title' => 'AUTOMETRIA Test',
        'body' => 'Cosmic Navy push',
        'url' => '/#/warehouse',
    ])->assertOk()->assertJsonPath('data.sent', true);

    Notification::assertSentTo(
        $this->fx->user,
        AutometriaWebPushNotification::class,
        function (AutometriaWebPushNotification $n): bool {
            return $n->title === 'AUTOMETRIA Test'
                && $n->body === 'Cosmic Navy push'
                && $n->url === '/#/warehouse';
        },
    );
});

it('User model uses HasPushSubscriptions and Notifiable', function (): void {
    $user = $this->fx->user;
    expect(class_uses_recursive($user))->toContain(
        \NotificationChannels\WebPush\HasPushSubscriptions::class,
        \Illuminate\Notifications\Notifiable::class,
    );
});
