# AUTOMETRIA ERP — common ops targets
# @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.

COMPOSE_DEV  := docker compose -f docker-compose.yml
COMPOSE_PROD := docker compose -f docker-compose.prod.yml --env-file .env.prod
SEED         ?= 0

.PHONY: help deploy-check prod-setup dev-setup prod-up prod-migrate prod-logs test build

help:
	@echo "Targets:"
	@echo "  make deploy-check   — validate compose + shell scripts (no side effects)"
	@echo "  make prod-setup     — full prod bootstrap (SEED=1 to run AcceptanceSeeder)"
	@echo "  make dev-setup      — local compose migrate (+ SEED=1)"
	@echo "  make prod-up        — compose prod up -d --build"
	@echo "  make prod-migrate   — migrate --force on prod app"
	@echo "  make prod-logs      — follow app/queue/scheduler/webserver"
	@echo "  make test           — php artisan test (dev app container)"
	@echo "  make build          — npm run build"

deploy-check:
	@bash -n scripts/deploy/prod-setup.sh
	@$(COMPOSE_DEV) config >/dev/null
	@echo "OK: prod-setup.sh syntax + docker-compose.yml"
	@if [ -f .env.prod ]; then $(COMPOSE_PROD) config >/dev/null && echo "OK: docker-compose.prod.yml"; else echo "SKIP: .env.prod not present (prod config)"; fi
	@./scripts/deploy/prod-setup.sh --mode=dev --dry-run --skip-build
	@echo "OK: deploy-check complete"

prod-setup:
	@chmod +x scripts/deploy/prod-setup.sh
	@if [ "$(SEED)" = "1" ]; then ./scripts/deploy/prod-setup.sh --mode=prod --seed; else ./scripts/deploy/prod-setup.sh --mode=prod; fi

dev-setup:
	@chmod +x scripts/deploy/prod-setup.sh
	@if [ "$(SEED)" = "1" ]; then ./scripts/deploy/prod-setup.sh --mode=dev --seed; else ./scripts/deploy/prod-setup.sh --mode=dev; fi

prod-up:
	$(COMPOSE_PROD) up -d --build

prod-migrate:
	$(COMPOSE_PROD) exec app php artisan migrate --force

prod-logs:
	$(COMPOSE_PROD) logs -f app webserver queue scheduler

test:
	$(COMPOSE_DEV) exec -T app php artisan test

build:
	npm run build
