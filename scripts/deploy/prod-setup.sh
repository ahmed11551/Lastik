#!/usr/bin/env bash
# AUTOMETRIA ERP — production / acceptance bootstrap helper
# @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
#
# Usage:
#   ./scripts/deploy/prod-setup.sh --mode=prod [--seed] [--skip-build] [--dry-run]
#   ./scripts/deploy/prod-setup.sh --mode=dev  [--seed] [--dry-run]
#
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT_DIR"

MODE="prod"
SEED=0
SKIP_BUILD=0
DRY_RUN=0
COMPOSE_FILE="docker-compose.prod.yml"
ENV_FILE=".env.prod"
APP_SERVICE="app"

log()  { printf '==> %s\n' "$*"; }
die()  { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

run() {
  if [[ "$DRY_RUN" -eq 1 ]]; then
    printf '[dry-run]'
    printf ' %q' "$@"
    printf '\n'
    return 0
  fi
  "$@"
}

compose() {
  if [[ "$MODE" == "prod" ]]; then
    docker compose -f "$COMPOSE_FILE" --env-file "$ENV_FILE" "$@"
  else
    docker compose -f "$COMPOSE_FILE" "$@"
  fi
}

usage() {
  cat <<'EOF'
prod-setup.sh — bootstrap AUTOMETRIA / LASTIK stack

Options:
  --mode=prod|dev   prod → docker-compose.prod.yml + .env.prod (default)
                    dev  → docker-compose.yml (local acceptance)
  --seed            run AcceptanceSeeder after migrate
  --skip-build      up -d without --build
  --dry-run         print actions only
  -h, --help        this help
EOF
}

for arg in "$@"; do
  case "$arg" in
    --mode=prod) MODE="prod" ;;
    --mode=dev)  MODE="dev" ;;
    --seed)      SEED=1 ;;
    --skip-build) SKIP_BUILD=1 ;;
    --dry-run)   DRY_RUN=1 ;;
    -h|--help)   usage; exit 0 ;;
    *) die "unknown option: $arg" ;;
  esac
done

if [[ "$MODE" == "dev" ]]; then
  COMPOSE_FILE="docker-compose.yml"
  ENV_FILE=""
fi

log "Root: $ROOT_DIR"
log "Mode: $MODE (compose=$COMPOSE_FILE)"

command -v docker >/dev/null 2>&1 || die "docker not found"
docker compose version >/dev/null 2>&1 || die "docker compose v2 plugin not found"
[[ -f "$COMPOSE_FILE" ]] || die "missing $COMPOSE_FILE"

if [[ "$MODE" == "prod" ]]; then
  [[ -f "$ENV_FILE" ]] || die "missing $ENV_FILE — copy .env.production.example → .env.prod and fill secrets"
  set -a
  # shellcheck disable=SC1090
  source "$ENV_FILE"
  set +a
  [[ -n "${APP_KEY:-}" ]] || die "APP_KEY is empty in $ENV_FILE"
  [[ -n "${DB_PASSWORD:-}" ]] || die "DB_PASSWORD is empty in $ENV_FILE"
  [[ -n "${REDIS_PASSWORD:-}" ]] || die "REDIS_PASSWORD is empty in $ENV_FILE"
  [[ -d dist/public ]] || die "missing dist/public — place encoded build before prod setup"
  [[ -f licensing/autometria.lic ]] || die "missing licensing/autometria.lic"
  [[ -f licensing/public.pem ]] || die "missing licensing/public.pem"
fi

log "Validating compose file…"
if [[ "$DRY_RUN" -eq 1 ]]; then
  printf '[dry-run] docker compose -f %s config\n' "$COMPOSE_FILE"
else
  compose config >/dev/null
fi

if [[ "$SKIP_BUILD" -eq 1 ]]; then
  log "Starting stack (no build)…"
  run compose up -d
else
  log "Building & starting stack…"
  run compose up -d --build
fi

log "Waiting for postgres health…"
if [[ "$DRY_RUN" -eq 0 ]]; then
  for i in $(seq 1 60); do
    if compose exec -T postgres pg_isready >/dev/null 2>&1; then
      break
    fi
    sleep 1
    [[ "$i" -eq 60 ]] && die "postgres not ready"
  done
fi

log "Running migrations (RLS included)…"
run compose exec -T "$APP_SERVICE" php artisan migrate --force

if [[ "$SEED" -eq 1 ]]; then
  log "Seeding AcceptanceSeeder…"
  run compose exec -T "$APP_SERVICE" php artisan db:seed --class='Database\Seeders\AcceptanceSeeder' --force
fi

log "Optimizing caches…"
run compose exec -T "$APP_SERVICE" php artisan config:cache
run compose exec -T "$APP_SERVICE" php artisan route:cache
run compose exec -T "$APP_SERVICE" php artisan view:cache
if [[ "$DRY_RUN" -eq 1 ]]; then
  run compose exec -T "$APP_SERVICE" php artisan storage:link
else
  compose exec -T "$APP_SERVICE" php artisan storage:link 2>/dev/null || true
fi

log "Clearing application data cache…"
if [[ "$DRY_RUN" -eq 1 ]]; then
  run compose exec -T "$APP_SERVICE" php artisan cache:clear
else
  compose exec -T "$APP_SERVICE" php artisan cache:clear 2>/dev/null || true
fi

log "Done."
if [[ "$MODE" == "prod" ]]; then
  log "HTTP: http://127.0.0.1:${HTTP_PORT:-80}/  (configure TLS via Certbot / reverse proxy)"
else
  log "HTTP: http://127.0.0.1:${APP_PORT:-8000}/"
fi
if [[ "$SEED" -eq 1 ]]; then
  log "Demo login: admin@lastik.local / password"
fi
