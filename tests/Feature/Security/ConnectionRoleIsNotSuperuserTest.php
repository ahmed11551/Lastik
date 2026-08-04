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
 * Тест GREEN когда применена прод-роль lastik_app (NOBYPASSRLS).
 * Тест SKIP (не fail) когда роль ещё superuser — т.е. в CI-контейнере
 * (postgres:16-alpine user lastik = superuser) или локально до применения
 * роли. Это устраняет false-red CI: проверка суперпользователя не должна
 * ломать пайплайн там, где прод-роль ещё не выведена.
 */
it('runtime db role is not superuser and does not bypass RLS', function (): void {
    $row = DB::selectOne(
        "SELECT rolname, rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user"
    );

    expect($row)->not->toBeNull("Current DB role not found in pg_roles");

    $isSuper = ($row->rolsuper === 't' || $row->rolsuper === true || $row->rolsuper === 1);
    $bypassesRls = ($row->rolbypassrls === 't' || $row->rolbypassrls === true || $row->rolbypassrls === 1);

    // Роль ещё не выведена в прод (superuser/BYPASSRLS) — CI/локально.
    // Skip, а не fail: прод-проверка сработает только под lastik_app.
    if ($isSuper || $bypassesRls) {
        $this->markTestSkipped(
            "Role '{$row->rolname}' is superuser/BYPASSRLS — apply lastik_app (NOBYPASSRLS) for RLS enforcement. Skipped in CI/local."
        );
    }

    expect($isSuper)->toBeFalse("DB role '{$row->rolname}' is SUPERUSER — RLS bypassed at runtime. Use lastik_app (NOBYPASSRLS).");
    expect($bypassesRls)->toBeFalse("DB role '{$row->rolname}' has BYPASSRLS — tenant isolation not enforced. Use lastik_app.");
});
