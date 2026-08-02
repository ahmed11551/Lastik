<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright  (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author     Себиев Ахмед Сулейманович
 * @license    Proprietary & Confidential.
 */

declare(strict_types=1);

namespace Autometria\Http\Controllers;

use Autometria\Enums\UserRoleEnum;
use Autometria\Models\Role;
use Autometria\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class UserManagementController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $users = User::query()
            ->with(['role:id,name,slug', 'location:id,name'])
            ->orderBy('name')
            ->get()
            ->map(function (User $user): array {
                $status = $user->is_active ? 'active' : 'suspended';
                if ($user->is_active && $user->last_login_at === null) {
                    $status = 'pending';
                }

                $roleLabel = UserRoleEnum::labelFor($user->role?->slug)
                    ?? $user->role?->name
                    ?? '—';

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $roleLabel,
                    'location' => $user->location?->name ?? '—',
                    'status' => $status,
                    'last_login' => $user->last_login_at?->format('Y-m-d H:i') ?? '—',
                ];
            })
            ->values()
            ->all();

        $roles = Role::query()
            ->orderBy('name')
            ->get(['name', 'slug'])
            ->map(fn (Role $role): string => UserRoleEnum::labelFor($role->slug) ?? $role->name)
            ->unique()
            ->values()
            ->all();

        if ($roles === []) {
            $roles = UserRoleEnum::displayLabels();
        }

        return Inertia::render('Users/Index', [
            'users' => $users,
            'roles' => $roles,
            'currentShiftOpen' => true,
            'shiftStartedAt' => now()->subHours(3)->toIso8601String(),
            'shiftRevenue' => 184500,
        ]);
    }
}
