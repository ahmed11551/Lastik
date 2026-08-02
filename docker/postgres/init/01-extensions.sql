-- AUTOMETRIA ERP — PostgreSQL bootstrap extensions
-- Applied once on first volume init (docker-entrypoint-initdb.d).
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
