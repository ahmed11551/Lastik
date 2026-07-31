<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

class OrderPolicy
{
    public function view(User $user, Order $order): bool
    {
        if ((int) $order->tenant_id !== (int) $user->tenant_id) {
            return false;
        }

        return $this->sameLocationOrAll($user, $order);
    }

    public function create(User $user): bool
    {
        return (int) $user->tenant_id > 0;
    }

    public function update(User $user, Order $order): bool
    {
        if ((int) $order->tenant_id !== (int) $user->tenant_id || (int) $user->tenant_id <= 0) {
            return false;
        }

        return $this->sameLocationOrAll($user, $order);
    }

    public function delete(User $user, Order $order): bool
    {
        return $this->update($user, $order);
    }

    public function cancel(User $user, Order $order): bool
    {
        if (! $this->update($user, $order)) {
            return false;
        }

        return in_array($order->status, [
            Order::STATUS_CREATED,
            Order::STATUS_IN_PROGRESS,
            Order::STATUS_READY,
        ], true);
    }

    private function sameLocationOrAll(User $user, Order $order): bool
    {
        $permissions = $user->role?->permissions ?? [];
        if (in_array('locations.all', $permissions, true) || in_array('admin.dashboard', $permissions, true)) {
            return true;
        }

        if ($user->location_id === null || $order->location_id === null) {
            return true;
        }

        return (int) $user->location_id === (int) $order->location_id;
    }
}
