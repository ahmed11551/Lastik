<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

namespace Autometria\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    public function boot(): void
    {
        parent::boot();
    }

    /**
     * Horizon dashboard access (non-local).
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function ($user = null) {
            if ($user === null) {
                return false;
            }

            $role = $user->role?->slug ?? $user->role?->name ?? null;

            return in_array($role, ['owner', 'admin', 'superadmin'], true)
                || in_array((string) ($user->email ?? ''), [
                    'admin@lastik.local',
                    'owner@lastik.local',
                ], true);
        });
    }
}
