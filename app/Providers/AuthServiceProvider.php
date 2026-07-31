<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\CashMovement;
use App\Models\CashShift;
use App\Models\ModuleRegistry;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use App\Policies\CashShiftPolicy;
use App\Policies\ModulePolicy;
use App\Policies\OrderPolicy;
use App\Policies\PaymentPolicy;
use App\Policies\SettingPolicy;
use App\Policies\UserPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [
        Order::class => OrderPolicy::class,
        Payment::class => PaymentPolicy::class,
        CashShift::class => CashShiftPolicy::class,
        User::class => UserPolicy::class,
        Setting::class => SettingPolicy::class,
        ModuleRegistry::class => ModulePolicy::class,
        CashMovement::class => CashShiftPolicy::class,
    ];
}
