# AUTOMETRIA ERP Engine Core

[![CI](https://github.com/ahmed11551/Lastik/actions/workflows/ci.yml/badge.svg)](https://github.com/ahmed11551/Lastik/actions/workflows/ci.yml)

Стек: Laravel 13, PHP 8.3+, PostgreSQL + React/Vite shell. Namespace: `Autometria\`.

> **Приёмка / smoke:** см. пошаговый гайд [docs/acceptance-run.md](docs/acceptance-run.md).  
> **Release Notes v1.0.0 + API:** [docs/release-notes-v1.0.0.md](docs/release-notes-v1.0.0.md).  
> **CommerceML batch:** [docs/commerceml-batch-architecture.md](docs/commerceml-batch-architecture.md).  
> **Licensing ops (PEM / lic):** [docs/licensing-ops.md](docs/licensing-ops.md).  
> **Encoded client build:** [docs/client-build-encoding.md](docs/client-build-encoding.md).

## Установка

```bash
cp .env.example .env
composer install
php artisan key:generate
docker compose up -d postgres redis          # дождаться healthcheck
# полный стек: docker compose up -d          # app + nginx + queue-worker + scheduler
php artisan migrate
php artisan db:seed --class=AcceptanceSeeder
```

### Docker-сервисы

| Сервис | Команда / роль |
|--------|----------------|
| `postgres` | Postgres 16, healthcheck `pg_isready` |
| `redis` | Redis 7, healthcheck `redis-cli ping` |
| `app` | PHP-приложение |
| `queue-worker` | `php artisan queue:work` |
| `scheduler` | `php artisan schedule:work` |
| `webserver` | nginx |

Тесты: `.env.testing` → `DB_DATABASE=lastik_test`. Acceptance: [docs/acceptance-run.md](docs/acceptance-run.md) (сценарии **49.1–49.21**).

## Переменные окружения

- `APP_URL` — корень приложения.
- `DB_CONNECTION=pgsql`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`.
- `DEFAULT_TENANT_SLUG` — slug тенанта по умолчанию для dev (`acceptance`).
- `VITE_API_BASE=/api/v1` — включить React → Laravel мост.
- `VITE_LARAVEL_PROXY=http://127.0.0.1:8000` — цель Vite proxy.

## Seeders

```bash
php artisan db:seed --class=AcceptanceSeeder
```

- Тенант `acceptance`, 2 точки, 2 склада, роли и ≥5 пользователей.
- Пароль демо: `password` (`admin@lastik.local`, `seller@lastik.local`, `cashier@lastik.local`, …).
- Открытая смена + TV-заказы для табло.

## Миграции

- Идемпотентные миграции PostgreSQL для всех доменных таблиц.
- RLS-политики tenant isolation в `2026_08_01_000004_enable_rls_for_tenant_scoped_tables.php`.
- Все денежные поля: `decimal(12,2)`.

## Hard Guards

1. Каждая tenant-scoped сущность имеет `tenant_id` и Global Query Scope.
2. Все операции Stock/CashShift/Payment/Reservation внутри `DB::transaction` + `lockForUpdate()`.
3. `decimal(12,2)` для всех денежных полей.
4. Идемпотентные миграции PostgreSQL, RLS-ready, без float.
5. Критичные мутации пишут в `AuditLog`.
6. Seeders: Roles, Permissions, Locations, Acceptance demo.
7. API v1: `/api/v1/orders`, `/api/v1/search`, `/api/v1/tv/board`, payments, shifts, stock, dictionaries, …
8. Заголовки контекста: `X-Tenant-ID`, `X-Tenant-Slug`, `X-Location-ID`.
9. React-клиент: `src/api/laravelClient.ts` (401/403/422 → UI).
10. README + [docs/acceptance-run.md](docs/acceptance-run.md).

## Примеры API

### Поиск (п. 36)
```bash
curl 'http://localhost:8000/api/v1/search?q=Алексей' \
  -H 'Accept: application/json' \
  -H 'X-Tenant-Slug: acceptance' \
  -H 'X-Location-ID: 1'
```

### TV-табло (п. 42)
```bash
curl 'http://localhost:8000/api/v1/tv/board?location_id=1' \
  -H 'Accept: application/json' \
  -H 'X-Tenant-Slug: acceptance'
```

### Создание оплаты
```bash
curl -X POST http://localhost:8000/api/v1/payments \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{"order_id":1,"method":"cash","amount":1000.00}'
```

## Тесты

```bash
# нужна БД lastik_test (создаётся в CI / локально):
# CREATE DATABASE lastik_test OWNER lastik;
./vendor/bin/pest
# или
php artisan test
```

Конфиг: PostgreSQL `lastik_test` (`phpunit.xml`). CI: [`.github/workflows/ci.yml`](.github/workflows/ci.yml).

Приёмочный контур: `ls tests/Feature/Acceptance*.php | xargs ./vendor/bin/pest`.

## Примечания

- React UI по умолчанию ходит в Express mock; Laravel включается через `VITE_API_BASE`.
- Поддержка UTF-8; модули через таблицу `modules` (`available|active|disabled|blocked`).
