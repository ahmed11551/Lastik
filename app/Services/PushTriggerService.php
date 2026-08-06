<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Services;

use Autometria\Models\Role;
use Autometria\Models\User;
use Autometria\Notifications\AutometriaWebPushNotification;
use Illuminate\Support\Facades\Notification;

/**
 * Fires Web Push (Cosmic Navy) to ERP actors on business events.
 * Graceful: no-op when no subscriptions / webpush not configured.
 */
final class PushTriggerService
{
    /**
     * Notify every user in the tenant that owns the given role slug.
     *
     * @param  int          $tenantId
     * @param  string       $roleSlug   e.g. 'admin', 'warehouse', 'cashier'
     * @param  string       $title
     * @param  string       $body
     * @param  string       $url        deep link opened on notification click
     * @param  string|null  $tag        collapse key
     * @param  bool         $requireInteraction
     * @return int          number of devices notified
     */
    public function notifyTenantRole(
        int $tenantId,
        string $roleSlug,
        string $title,
        string $body,
        string $url = '/',
        ?string $tag = null,
        bool $requireInteraction = false,
    ): int {
        $role = Role::where('slug', $roleSlug)->first();
        if ($role === null) {
            return 0;
        }

        $users = $role->users()
            ->where('tenant_id', $tenantId)
            ->whereHas('pushSubscriptions')
            ->get();

        if ($users->isEmpty()) {
            return 0;
        }

        $notification = new AutometriaWebPushNotification(
            title: $title,
            body: $body,
            url: $url,
            tag: $tag ?? ('autometria-' . $roleSlug),
            requireInteraction: $requireInteraction,
        );

        Notification::send($users, $notification);

        return $users->sum(static fn (User $u) => $u->pushSubscriptions()->count());
    }

    /**
     * Notify all tenant users (any role) that have a push subscription.
     */
    public function notifyTenant(
        int $tenantId,
        string $title,
        string $body,
        string $url = '/',
        ?string $tag = null,
        bool $requireInteraction = false,
    ): int {
        $users = User::where('tenant_id', $tenantId)
            ->whereHas('pushSubscriptions')
            ->get();

        if ($users->isEmpty()) {
            return 0;
        }

        $notification = new AutometriaWebPushNotification(
            title: $title,
            body: $body,
            url: $url,
            tag: $tag ?? 'autometria-tenant',
            requireInteraction: $requireInteraction,
        );

        Notification::send($users, $notification);

        return $users->sum(static fn (User $u) => $u->pushSubscriptions()->count());
    }

    // --- Business event helpers (Sprint 4) ---

    public function lowStock(int $tenantId, string $productName, float $available): int
    {
        return $this->notifyTenantRole(
            tenantId: $tenantId,
            roleSlug: 'admin',
            title: '⚠️ Низкий остаток',
            body: sprintf('%s: осталось %.3f — требуется закупка (Smart Procurement).', $productName, $available),
            url: '/inventory/reorder',
            tag: 'autometria-low-stock',
            requireInteraction: true,
        );
    }

    public function shiftClosed(int $tenantId, string $cashierName, string $zReportRef): int
    {
        return $this->notifyTenantRole(
            tenantId: $tenantId,
            roleSlug: 'admin',
            title: 'Z-отчёт сформирован',
            body: sprintf('Кассир %s закрыл смену (%s).', $cashierName, $zReportRef),
            url: '/cash/shift',
            tag: 'autometria-shift-closed',
        );
    }

    public function cashierSwitched(int $tenantId, string $fromName, string $toName): int
    {
        return $this->notifyTenantRole(
            tenantId: $tenantId,
            roleSlug: 'admin',
            title: 'Смена кассира',
            body: sprintf('%s → %s передал кассу.', $fromName, $toName),
            url: '/cash/shift',
            tag: 'autometria-cashier-switch',
        );
    }

    public function stockTransferCreated(
        int $tenantId,
        string $productName,
        string $fromWarehouse,
        string $toWarehouse,
        float $qty,
    ): int {
        return $this->notifyTenantRole(
            tenantId: $tenantId,
            roleSlug: 'warehouse',
            title: '📦 Новое перемещение',
            body: sprintf('%s: %.3f из «%s» в «%s».', $productName, $qty, $fromWarehouse, $toWarehouse),
            url: '/wms/transfers',
            tag: 'autometria-transfer',
        );
    }

    /**
     * Fire a push callback without ever breaking the surrounding business
     * transaction. Any failure is swallowed and logged.
     *
     * @param  callable(): int  $fn
     */
    public static function notifySafe(callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            // Push is best-effort; never abort the ERP operation.
            report($e);
        }
    }
}
