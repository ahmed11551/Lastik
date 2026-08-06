<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Http\Controllers;

use Autometria\Models\User;
use Autometria\Notifications\AutometriaWebPushNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;

class PushSubscriptionController extends Controller
{
    /**
     * Public VAPID key for PushManager.subscribe (safe to expose).
     */
    public function vapidPublicKey(): JsonResponse
    {
        $key = (string) config('webpush.vapid.public_key', '');

        return response()->json([
            'data' => [
                'public_key' => $key,
                'configured' => $key !== '',
            ],
        ]);
    }

    /**
     * Upsert browser push subscription for the authenticated user.
     */
    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:500'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'content_encoding' => ['nullable', 'string', 'max:32'],
        ]);

        $subscription = $user->updatePushSubscription(
            $data['endpoint'],
            $data['keys']['p256dh'],
            $data['keys']['auth'],
            $data['content_encoding'] ?? 'aesgcm',
        );

        return response()->json([
            'data' => [
                'id' => $subscription->id,
                'endpoint' => $subscription->endpoint,
                'content_encoding' => $subscription->content_encoding,
            ],
        ], 201);
    }

    /**
     * Remove push subscription by endpoint.
     */
    public function destroy(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:500'],
        ]);

        $user->deletePushSubscription($data['endpoint']);

        return response()->json(['data' => ['deleted' => true]]);
    }

    /**
     * Send a Cosmic Navy smoke notification to the current user's devices.
     */
    public function test(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->pushSubscriptions()->count() === 0) {
            return response()->json([
                'message' => 'Нет активных push-подписок. Сначала зарегистрируйте устройство.',
            ], 422);
        }

        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:120'],
            'body' => ['nullable', 'string', 'max:500'],
            'url' => ['nullable', 'string', 'max:500'],
        ]);

        Notification::send(
            $user,
            new AutometriaWebPushNotification(
                title: $data['title'] ?? 'AUTOMETRIA',
                body: $data['body'] ?? 'Web Push работает. Cosmic Navy · v1.3.0',
                url: $data['url'] ?? '/',
                tag: 'autometria-test',
            ),
        );

        return response()->json([
            'data' => [
                'sent' => true,
                'subscriptions' => $user->pushSubscriptions()->count(),
            ],
        ]);
    }
}
