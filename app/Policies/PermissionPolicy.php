<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

class PermissionPolicy
{
    public function view(User $user): bool
    {
        return (int) $user->tenant_id > 0;
    }
}
