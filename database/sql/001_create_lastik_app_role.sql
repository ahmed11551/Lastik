-- AUTOMETRIA ERP Engine Core
-- F2: роль времени выполнения приложения (runtime role) без BYPASSRLS.
-- Применяется founder-ом на стейджинге/проде ПОСЛЕ заполнения БД под lastik (superuser).
-- ВНИМАНИЕ: эта роль НЕ суперпользователь и НЕ обходит RLS — RLS становится
-- реальной второй линией защиты (defense-in-depth) для изоляции тенантов.

-- Создание роли (идемпотентно).
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'lastik_app') THEN
        CREATE ROLE lastik_app LOGIN PASSWORD 'CHANGE_ME_STRONG_PASSWORD'
            NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE;
    END IF;
END
$$;

-- Права на схему public (DML + sequences). DDL остаётся за lastik (миграции).
GRANT USAGE ON SCHEMA public TO lastik_app;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO lastik_app;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO lastik_app;

-- Будущие таблицы/последовательности (после следующих миграций).
ALTER DEFAULT PRIVILEGES IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO lastik_app;
ALTER DEFAULT PRIVILEGES IN SCHEMA public
    GRANT USAGE, SELECT ON SEQUENCES TO lastik_app;

-- Подсказка: после применения обновить .env
--   DB_USERNAME=lastik_app
--   DB_PASSWORD=<CHANGE_ME_STRONG_PASSWORD>
-- и выполнить: php artisan config:clear
