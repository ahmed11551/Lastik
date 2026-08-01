<?php

/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author Себиев Ахмед Сулейманович
 * @license Proprietary & Confidential.
 */

declare(strict_types=1);

namespace Autometria\Http\Controllers;

use Autometria\Models\Location;
use Autometria\Models\Role;
use Autometria\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $users = User::query()
            ->with(['role:id,name,slug', 'location:id,name'])
            ->orderBy('name')
            ->get()
            ->map(fn (User $u) => $this->present($u));

        $roles = Role::query()
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);

        return response()->json([
            'data' => $users,
            'meta' => ['roles' => $roles],
        ]);
    }

    public function show(User $user): JsonResponse
    {
        $this->authorize('view', $user);
        $user->load(['role:id,name,slug', 'location:id,name']);

        return response()->json(['data' => $this->present($user)]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user() !== null, 401);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:40'],
            'password' => ['required', 'string', 'min:6', 'max:100'],
            'role_id' => ['required', 'integer', 'exists:roles,id'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $tenantId = (int) ($request->user()->tenant_id ?? tenant_id() ?? 0);
        abort_unless($tenantId > 0, 422, 'Tenant context required');

        $role = Role::query()->findOrFail((int) $data['role_id']);
        if (isset($role->tenant_id) && (int) $role->tenant_id !== $tenantId && (int) $role->tenant_id !== 0) {
            abort(422, 'Role does not belong to tenant');
        }

        $locationId = isset($data['location_id'])
            ? (int) $data['location_id']
            : (int) ($request->user()->location_id ?? 0);

        if ($locationId > 0) {
            abort_unless(
                Location::query()->whereKey($locationId)->where('tenant_id', $tenantId)->exists(),
                422,
                'Invalid location',
            );
        }

        $user = User::query()->create([
            'tenant_id' => $tenantId,
            'location_id' => $locationId ?: null,
            'role_id' => (int) $data['role_id'],
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'password_hash' => Hash::make($data['password']),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'devices_limit' => 2,
        ]);

        $user->load(['role:id,name,slug', 'location:id,name']);

        return response()->json(['data' => $this->present($user)], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $this->authorize('update', $user);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'email' => ['sometimes', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:40'],
            'role_id' => ['sometimes', 'integer', 'exists:roles,id'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'is_active' => ['nullable', 'boolean'],
            'password' => ['nullable', 'string', 'min:6', 'max:100'],
        ]);

        if (array_key_exists('password', $data) && $data['password']) {
            $user->password_hash = Hash::make($data['password']);
            unset($data['password']);
        }

        $user->fill($data);
        $user->save();
        $user->load(['role:id,name,slug', 'location:id,name']);

        return response()->json(['data' => $this->present($user)]);
    }

    private function present(User $u): array
    {
        return [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'phone' => $u->phone,
            'role_id' => $u->role_id,
            'role' => $u->role?->name ?: '—',
            'role_slug' => $u->role?->slug,
            'location_id' => $u->location_id,
            'location' => $u->location?->name ?: '—',
            'status' => $u->is_active ? 'active' : 'suspended',
            'is_active' => (bool) $u->is_active,
            'last_login' => optional($u->last_login_at)?->format('Y-m-d H:i') ?: '—',
        ];
    }
}
