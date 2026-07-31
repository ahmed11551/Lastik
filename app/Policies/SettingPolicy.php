<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

class SettingPolicy
{
    public function view(User $user): bool
    {
        return (int) $user->tenant_id > 0;
    }

    public function update(User $user): bool
    {
        return (int) $user->tenant_id > 0;
    }
}
