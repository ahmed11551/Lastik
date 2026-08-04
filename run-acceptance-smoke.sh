#!/usr/bin/env bash
# AUTOMETRIA ERP — Full-stack acceptance smoke (Layers 1–5 + PHPStan L8)
# Usage: ./run-acceptance-smoke.sh
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT"

RED=$'\033[0;31m'
GREEN=$'\033[0;32m'
YELLOW=$'\033[0;33m'
BOLD=$'\033[1m'
RESET=$'\033[0m'

status_final="RED"

finish() {
  echo ""
  if [[ "${status_final}" == "GREEN" ]]; then
    echo "${BOLD}${GREEN}==> [GREEN] AUTOMETRIA Engine Core accepted (Layers 1–5).${RESET}"
    exit 0
  fi
  echo "${BOLD}${RED}==> [RED] Acceptance smoke failed.${RESET}"
  exit 1
}
trap finish EXIT

echo "==> Starting Lastik Core Full-Stack Acceptance Pipeline..."

echo "==> [1/6] Orchestrating Docker containers..."
docker compose up -d

echo "==> waiting for postgres + redis health"
for i in $(seq 1 60); do
  pg_ok=0
  redis_ok=0
  docker compose exec -T postgres pg_isready -U lastik -d lastik >/dev/null 2>&1 && pg_ok=1
  docker compose exec -T redis redis-cli ping 2>/dev/null | grep -q PONG && redis_ok=1
  if [[ "$pg_ok" -eq 1 && "$redis_ok" -eq 1 ]]; then
    break
  fi
  sleep 1
  if [[ "$i" -eq 60 ]]; then
    echo "Timeout waiting for postgres/redis"
    exit 1
  fi
done

echo "==> ensure lastik_test + RLS probe role"
docker compose exec -T postgres psql -U lastik -d lastik -tc "SELECT 1 FROM pg_database WHERE datname='lastik_test'" | grep -q 1 \
  || docker compose exec -T postgres psql -U lastik -d lastik -c "CREATE DATABASE lastik_test;"

docker compose exec -T postgres psql -U lastik -d lastik_test <<'SQL' >/dev/null
DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'lastik_rls_probe') THEN
    CREATE ROLE lastik_rls_probe NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE INHERIT LOGIN PASSWORD 'secret';
  END IF;
END
$$;
GRANT USAGE ON SCHEMA public TO lastik_rls_probe;
GRANT lastik_rls_probe TO lastik;
SQL

echo "==> [2/6] Verifying asynchronous queue workers..."
if docker compose ps --status running --services 2>/dev/null | grep -qx 'queue-worker'; then
  echo -e "${GREEN}[OK] Dedicated queue-worker container is up.${RESET}"
elif docker compose exec -T queue-worker ps aux 2>/dev/null | grep -q '[q]ueue:work'; then
  echo -e "${GREEN}[OK] queue:work process detected in queue-worker.${RESET}"
else
  echo -e "${YELLOW}[WARN] queue-worker not detected — bringing stack services up...${RESET}"
  docker compose up -d queue-worker
fi

echo "==> [3/6] Database migrate + AcceptanceSeeder..."
docker compose exec -T \
  -e DB_HOST=postgres -e DB_PORT=5432 -e DB_DATABASE=lastik \
  -e DB_USERNAME=lastik -e DB_PASSWORD=secret \
  app php artisan migrate --force

docker compose exec -T \
  -e DB_HOST=postgres -e DB_PORT=5432 -e DB_DATABASE=lastik \
  -e DB_USERNAME=lastik -e DB_PASSWORD=secret \
  app php artisan db:seed --class=AcceptanceSeeder --force

echo "==> [4/6] Migrate lastik_test for Pest..."
docker compose exec -T \
  -e APP_ENV=testing \
  -e DB_HOST=postgres -e DB_PORT=5432 -e DB_DATABASE=lastik_test \
  -e DB_USERNAME=lastik -e DB_PASSWORD=secret \
  app php artisan migrate --force

PEST_FILTER='AcceptanceCoreSecurityTest|AcceptanceTvBoardTest|AcceptanceCommerceMlBatchTest|AcceptanceEndToEndBusinessTest'

echo "==> [5/6] Pest Layers 1–5 (${PEST_FILTER})..."
set +e
docker compose exec -T \
  -e APP_ENV=testing \
  -e DB_HOST=postgres -e DB_PORT=5432 -e DB_DATABASE=lastik_test \
  -e DB_USERNAME=lastik -e DB_PASSWORD=secret \
  -e CACHE_STORE=array -e QUEUE_CONNECTION=sync \
  app php artisan test --filter="${PEST_FILTER}"
pest_rc=$?
set -e

if [[ "$pest_rc" -ne 0 ]]; then
  echo -e "${RED}[FAIL] Business logic or async pipeline breached!${RESET}"
  exit 1
fi
echo -e "${GREEN}[PASS] Layers 1–5 Pest suite verified.${RESET}"

echo "==> [6/6] PHPStan Level 8..."
set +e
docker compose exec -T app composer analyse
stan_rc=$?
set -e
if [[ "$stan_rc" -ne 0 ]]; then
  set +e
  composer analyse
  stan_rc=$?
  set -e
fi
if [[ "$stan_rc" -ne 0 ]]; then
  echo -e "${RED}[FAIL] Static analysis found strict type errors.${RESET}"
  exit 1
fi
echo -e "${GREEN}[PASS] PHPStan Level 8 analysis clean.${RESET}"

status_final="GREEN"
