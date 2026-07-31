<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\CashShift;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

class RouteServiceProvider extends ServiceProvider
{
    public function boot(Router $router): void
    {
        $router->model('order', Order::class);
        $router->model('payment', Payment::class);
        $router->model('shift', CashShift::class);
        $router->model('user', User::class);
    }
}
