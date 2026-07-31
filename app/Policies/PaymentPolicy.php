<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;

class PaymentPolicy
{
    public function view(User $user, Payment $payment): bool
    {
        return (int) $payment->tenant_id === (int) $user->tenant_id;
    }

    public function create(User $user): bool
    {
        return (int) $user->tenant_id > 0;
    }

    public function update(User $user, Payment $payment): bool
    {
        return (int) $payment->tenant_id === (int) $user->tenant_id;
    }

    public function correct(User $user, Payment $payment): bool
    {
        return (int) $payment->tenant_id === (int) $user->tenant_id && $payment->status !== 'released';
    }
}
