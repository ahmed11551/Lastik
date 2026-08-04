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
use Tests\TestCase;

/*
 * В тест-среде отключаем named throttle-лимиты (auth-api и пр.),
 * чтобы параллельные Pest-воркеры не делили общий лимит и не ловили
 * ложные 429. Сами по себе rate-limit мы здесь не тестируем; лимит
 * активных устройств проверяется отдельным кастомным гвардом (не throttle).
 */
if (env('APP_ENV') === 'testing') {
    app()->bind(ThrottleRequests::class, static fn (): object => new class {
        public function handle($request, $next): mixed
        {
            return $next($request);
        }
    });
}

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->in('Unit');
