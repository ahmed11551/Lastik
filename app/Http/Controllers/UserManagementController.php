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

use Autometria\Models\Role;
use Autometria\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class UserManagementController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $roleLabels = [
            'admin' => 'Администратор',
            'owner' => 'Администратор',
            'master' => 'Мастер-приемщик',
            'seller' => 'Мастер-приемщик',
            'warehouse_manager' => 'Кладовщик',
            'cashier' => 'Бухгалтер',
        ];

        $users = User::query()
            ->with(['role:id,name,slug', 'location:id,name'])
            ->orderBy('name')
            ->get()
            ->map(function (User $user) use ($roleLabels): array {
                $slug = $user->role?->slug;
                $status = $user->is_active ? 'active' : 'suspended';
                if ($user->is_active && $user->last_login_at === null) {
                    $status = 'pending';
                }

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $roleLabels[$slug] ?? ($user->role?->name ?? '—'),
                    'location' => $user->location?->name ?? '—',
                    'status' => $status,
                    'last_login' => $user->last_login_at?->format('Y-m-d H:i') ?? '—',
                ];
            })
            ->values()
            ->all();

        $roles = Role::query()
            ->orderBy('name')
            ->pluck('name')
            ->map(fn (string $name): string => match (true) {
                str_contains(mb_strtolower($name), 'админ') => 'Администратор',
                str_contains(mb_strtolower($name), 'мастер') => 'Мастер-приемщик',
                str_contains(mb_strtolower($name), 'клад') => 'Кладовщик',
                str_contains(mb_strtolower($name), 'касс') => 'Бухгалтер',
                default => $name,
            })
            ->unique()
            ->values()
            ->all();

        if ($roles === []) {
            $roles = ['Администратор', 'Мастер-приемщик', 'Кладовщик', 'Бухгалтер'];
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
