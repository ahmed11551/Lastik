<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @package    Autometria\Core
 * @copyright  (c) 2026 Себиев Ахмед Сулейманович (Sebiev Akhmed Suleymanovich). All Rights Reserved.
 * @author     Себиев Ахмед Сулейманович (Chief Software Architect / Lead Developer)
 * @license    Proprietary & Confidential. Unauthorized copying, distribution,
 *             modification, or reverse engineering of this file, via any medium,
 *             is strictly prohibited.
 *
 * NOTICE: All information contained herein is, and remains the property of
 * Себиев Ахмед Сулейманович. The intellectual and technical concepts contained
 * herein are proprietary and protected by trade secret and copyright law.
 */
/**
 * LASTIK B2B SaaS Engine Core
 *
 * @copyright  (c) 2026 Себиев Ахмед Сулейманович (Sebiev Akhmed Suleymanovich). All Rights Reserved.
 * @author     Себиев Ахмед Сулейманович (Chief Software Architect / Lead Developer)
 * @license    Proprietary & Confidential. Unauthorized copying, distribution,
 *             modification, or reverse engineering of this file, via any medium,
 *             is strictly prohibited.
 *
 * NOTICE: All information contained herein is, and remains the property of
 * Себиев Ахмед Сулейманович. The intellectual and technical concepts contained
 * herein are proprietary and protected by trade secret and copyright law.
 */
/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author Себиев Ахмед Сулейманович
 * @license Proprietary & Confidential.
 */

declare(strict_types=1);

namespace Autometria\Providers;

use Autometria\Models\CashShift;
use Autometria\Models\Order;
use Autometria\Models\Payment;
use Autometria\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class RouteServiceProvider extends ServiceProvider
{
    public function boot(Router $router): void
    {
        $this->configureRateLimiting();

        $router->model('order', Order::class);
        $router->model('payment', Payment::class);
        $router->model('shift', CashShift::class);
        $router->model('user', User::class);
    }

    /**
     * Named rate limiters for production readiness (Block 5.3).
     * - auth-api: public/login — 10 req/min per IP
     * - pos-api: кассовые API — 120 req/min per user/IP
     * - api: общий authenticated API — 600 req/min (test-suite safe)
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('auth-api', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip() ?? 'unknown');
        });

        RateLimiter::for('pos-api', function (Request $request) {
            $id = $request->user()?->id ?: $request->ip();

            return Limit::perMinute(120)->by('pos|'.$id);
        });

        RateLimiter::for('api', function (Request $request) {
            $id = $request->user()?->id ?: $request->ip();

            return Limit::perMinute(600)->by('api|'.$id);
        });
    }
}
