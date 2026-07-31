<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CashShift;
use App\Models\User;

class CashShiftPolicy
{
    public function view(User $user, CashShift $shift): bool
    {
        return (int) $shift->tenant_id === (int) $user->tenant_id;
    }

    public function create(User $user): bool
    {
        return (int) $user->tenant_id > 0;
    }

    public function close(User $user, CashShift $shift): bool
    {
        return (int) $shift->tenant_id === (int) $user->tenant_id
            && in_array((string) $shift->status, ['open', 'pending'], true);
    }
}
