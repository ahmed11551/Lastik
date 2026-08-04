<?php

/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author Себиев Ахмед Сулейманович
 * @license Proprietary & Confidential.
 */

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Autometria\Http\Middleware\RateLimitAuth;
use Tests\TestCase;

/*
 * В тест-среде отключаем rate-limit гварды (throttle + RateLimitAuth),
 * чтобы параллельные Pest-воркеры не делили общий per-IP лимит и не ловили
 * ложные 429. Сами по себе rate-limit мы здесь не тестируем; лимит активных
 * устройств проверяется отдельным кастомным гвардом (не затрагивается).
 */
if (env('APP_ENV') === 'testing') {
    $noOp = static fn (): object => new class {
        public function handle($request, $next): mixed
        {
            return $next($request);
        }
    };
    app()->bind(ThrottleRequests::class, $noOp);
    app()->bind(RateLimitAuth::class, $noOp);
}

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->in('Unit');
