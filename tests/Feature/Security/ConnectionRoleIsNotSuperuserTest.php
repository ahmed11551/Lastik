<?php

/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author Себиев Ахмед Сулейманович
 * @license Proprietary & Confidential.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/**
 * F2 / Gate P0: роль подключения приложения НЕ должна быть суперпользователем
 * и НЕ должна иметь BYPASSRLS. Иначе RLS игнорируется и изоляция тенантов
 * не гарантируется на уровне выполнения.
 *
 * В CI/проде (APP_ENV=production) тест ПАДАЕТ, если DB_USERNAME указывает на
 * суперпользователя. Локально (APP_ENV != production) — пропускается с warning.
 */
it('runtime db role is not superuser and does not bypass RLS', function (): void {
    if ((string) env('APP_ENV', 'production') !== 'production') {
        $this->markTestSkipped('Local dev: role guard enforced only in production (set DB_USERNAME=lastik_app).');
    }

    $row = DB::selectOne(
        "SELECT rolname, rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user"
    );

    expect($row)->not->toBeNull("Current DB role not found in pg_roles");

    $isSuper = ($row->rolsuper === 't' || $row->rolsuper === true || $row->rolsuper === 1);
    $bypassesRls = ($row->rolbypassrls === 't' || $row->rolbypassrls === true || $row->rolbypassrls === 1);

    expect($isSuper)->toBeFalse("DB role '{$row->rolname}' is SUPERUSER — RLS bypassed at runtime. Use lastik_app (NOBYPASSRLS).");
    expect($bypassesRls)->toBeFalse("DB role '{$row->rolname}' has BYPASSRLS — tenant isolation not enforced. Use lastik_app.");
});
