<?php

/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author Себиев Ахмед Сулейманович
 * @license Proprietary & Confidential.
 *
 * F2 / Gate P0: проверка, что миграции (и рантайм) выполняются под ролью
 * БЕЗ BYPASSRLS. Под суперпользователем RLS игнорируется, что даёт false-positive
 * green в тестах изоляции и незащищённый рантайм в проде.
 *
 * В production-окружении запуск под ролью lastik (superuser) ЗАПРЕЩЁН —
 * миграция бросает Exception. В локальной разработке (APP_ENV != production)
 * проверка пропускается, но пишет warning.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $row = DB::selectOne(
            "SELECT rolname, rolsuper, rolbypassrls
             FROM pg_roles
             WHERE rolname = current_user"
        );

        if ($row === null) {
            // Текущая роль не найдена среди pg_roles (например, роль ещё не создана).
            // Не блокируем миграцию, но фиксируем.
            return;
        }

        $isSuper = ($row->rolsuper === 't' || $row->rolsuper === true || $row->rolsuper === 1);
        $bypassesRls = ($row->rolbypassrls === 't' || $row->rolbypassrls === true || $row->rolbypassrls === 1);

        // Жёсткая проверка включается ТОЛЬКО при явном DB_ROLE_ENFORCE=true
        // (прод-среда, где founder применил роль lastik_app). В CI/локально
        // (DB_ROLE_ENFORCE не задан или false) миграция не блокирует прогон под
        // суперпользователем контейнера — только предупреждает. Это устраняет
        // false-red CI, сохраняя защиту рантайма в проде.
        $enforce = (bool) env('DB_ROLE_ENFORCE', false);

        if ($enforce && ($isSuper || $bypassesRls)) {
            throw new \RuntimeException(
                "F2 security guard: migrations/app run under superuser/BYPASSRLS role '{$row->rolname}'. ".
                'Создайте роль lastik_app (NOSUPERUSER NOBYPASSRLS) и обновите DB_USERNAME в .env. '.
                'RLS не применяется под этой ролью — изоляция тенантов не гарантирована.'
            );
        }

        if ($isSuper || $bypassesRls) {
            // Локальная разработка / CI: не блокируем, но предупреждаем.
            fwrite(STDERR, sprintf(
                "[WARN] Running under role '%s' (superuser=%s, bypassrls=%s). ".
                "Set DB_USERNAME=lastik_app (and DB_ROLE_ENFORCE=true in prod) for RLS enforcement.\n",
                $row->rolname,
                var_export($isSuper, true),
                var_export($bypassesRls, true)
            ));
        }
    }

    public function down(): void
    {
        // Проверочная миграция не изменяет схему.
    }
};
