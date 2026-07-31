<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function view(User $user, User $model): bool
    {
        return (int) $model->tenant_id === (int) $user->tenant_id;
    }

    public function update(User $user, User $model): bool
    {
        return (int) $model->tenant_id === (int) $user->tenant_id;
    }

    public function delete(User $user, User $model): bool
    {
        return (int) $model->tenant_id === (int) $user->tenant_id;
    }
}
