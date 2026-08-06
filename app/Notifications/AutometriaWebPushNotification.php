<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Notifications;

use Autometria\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Generic Cosmic Navy Web Push payload for Autometria ERP.
 */
class AutometriaWebPushNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $title,
        public string $body,
        public string $url = '/',
        public ?string $tag = null,
        public bool $requireInteraction = false,
        /** @var array<string, mixed> */
        public array $data = [],
    ) {
        $this->onQueue('default');
    }

    /**
     * @return list<string|class-string>
     */
    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable, self $notification): WebPushMessage
    {
        $icon = url('/icons/icon-192.svg');

        $payloadData = array_merge([
            'url' => $this->url,
            'tenant_id' => $notifiable instanceof User ? (int) $notifiable->tenant_id : null,
        ], $this->data);

        $message = (new WebPushMessage)
            ->title($this->title)
            ->body($this->body)
            ->icon($icon)
            ->badge($icon)
            ->data($payloadData)
            ->options(['TTL' => 3600]);

        if ($this->tag !== null && $this->tag !== '') {
            $message->tag($this->tag)->renotify(true);
        }

        if ($this->requireInteraction) {
            $message->requireInteraction(true);
        }

        $message->action('Открыть', 'open');

        return $message;
    }
}
