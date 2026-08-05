-- AUTOMETRIA ERP — Application runtime role (defense-in-depth RLS)
-- Applied once on first volume init (docker-entrypoint-initdb.d).
--
-- prod docker-compose.yml connects as DB_USERNAME=autometria_user.
-- This role is NOSUPERUSER + NOBYPASSRLS so Row Level Security policies
-- are ENFORCED at the database layer (not just Laravel global scopes).
-- Laravel still sets app.current_tenant_id per request; RLS filters rows
-- by tenant_id = current_setting('app.current_tenant_id').

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'autometria_user') THEN
        CREATE ROLE autometria_user NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE INHERIT LOGIN PASSWORD 'change-me-strong-app-password';
    END IF;
END
$$;

-- Schema + object privileges for the runtime role.
GRANT USAGE, CREATE ON SCHEMA public TO autometria_user;
GRANT SELECT, INSERT, UPDATE, DELETE, REFERENCES ON ALL TABLES IN SCHEMA public TO autometria_user;
GRANT USAGE, SELECT, UPDATE ON ALL SEQUENCES IN SCHEMA public TO autometria_user;

-- Future tables/sequences created after init also get privileges.
ALTER DEFAULT PRIVILEGES IN SCHEMA public
    GRANT SELECT, INSERT, UPDATE, DELETE, REFERENCES ON TABLES TO autometria_user;
ALTER DEFAULT PRIVILEGES IN SCHEMA public
    GRANT USAGE, SELECT, UPDATE ON SEQUENCES TO autometria_user;

-- Allow the bootstrap superuser to SET ROLE into the app role if needed.
GRANT autometria_user TO lastik;
