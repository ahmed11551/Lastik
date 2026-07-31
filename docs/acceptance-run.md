# Smoke Test Guide — LASTIK Acceptance

Пошаговый запуск приёмочного окружения (п. 45) с Laravel API и React-мостом.

## 0. Требования

- Docker Desktop (Postgres 16 + Redis 7)
- PHP 8.3+, Composer
- Node.js 20+ (Vite / React)

## 1. Инфраструктура

```bash
cd /path/to/Lastik-main
docker compose up -d postgres redis
# опционально полный стек: docker compose up -d
```

Дождитесь healthcheck Postgres (`pg_isready`).

## 2. Backend: env, миграции, сидер

```bash
cp .env.example .env   # если ещё нет Laravel .env
# Минимум для локали:
# APP_KEY=... (php artisan key:generate)
# DB_CONNECTION=pgsql
# DB_HOST=127.0.0.1
# DB_PORT=5432
# DB_DATABASE=lastik
# DB_USERNAME=lastik
# DB_PASSWORD=secret
# DEFAULT_TENANT_SLUG=acceptance
# QUEUE_CONNECTION=redis
# REDIS_HOST=127.0.0.1

composer install
php artisan key:generate
php artisan migrate:fresh --force
php artisan db:seed --class=AcceptanceSeeder
```

Сидер создаёт:

| Сущность | Значение |
|----------|----------|
| Тенант | `acceptance` — «Приёмочный шинный центр» |
| Точки | Точка Север, Точка Юг |
| Склады | Склад Север / Склад Юг |
| Роли | owner, admin, seller, cashier, master, warehouse_manager |
| Смена | открытая на Точке Север |
| TV-заказы | `TV-QUEUE-1`, `TV-WORK-1`, `TV-READY-1` (with_installation) |

### Учётные данные (пароль у всех: `password`)

| Роль | Email |
|------|-------|
| Owner | `owner@lastik.local` |
| Admin | `admin@lastik.local` |
| Seller | `seller@lastik.local` |
| Cashier | `cashier@lastik.local` |
| Master | `master@lastik.local` |
| Warehouse | `warehouse@lastik.local` |

## 3. Laravel API

```bash
php artisan serve --host=127.0.0.1 --port=8000
```

Проверка:

```bash
curl -s -o /dev/null -w "%{http_code}\n" \
  -H "X-Tenant-Slug: acceptance" \
  http://127.0.0.1:8000/api/v1/search?q=Алексей
# ожидается 401 без сессии (маршрут под auth) — сервис жив
```

После логина (session/cookie или Sanctum — по текущей конфигурации Auth):

```bash
curl -s 'http://127.0.0.1:8000/api/v1/search?q=Алексей' \
  -H 'Accept: application/json' \
  -H 'X-Tenant-ID: 1' \
  -H 'X-Tenant-Slug: acceptance' \
  -H 'X-Location-ID: 1' \
  -b 'laravel_session=...'
```

Ключевые маршруты моста:

- `GET /api/v1/search?q=`
- `GET /api/v1/tv/board?location_id=`

Заголовки, которые шлёт React (`src/api/laravelClient.ts`):

- `X-Tenant-ID`
- `X-Tenant-Slug` (по умолчанию `acceptance`)
- `X-Location-ID`

Ошибки `401` / `403` / `422` мапятся в `LaravelApiError` и показываются в Header (поиск) и TV-табло.

## 4. Frontend с Laravel proxy

В `.env` (корень Vite-проекта):

```bash
VITE_API_BASE=/api/v1
VITE_LARAVEL_PROXY=http://127.0.0.1:8000
```

```bash
npm install
npm run dev
```

Vite проксирует `/api/v1/*` на Laravel (`vite.config.ts`). Без `VITE_API_BASE` UI остаётся на Express mock (`/api/bootstrap`).

### Smoke checklist UI

1. Открыть приложение, перейти на вкладку TV — видны колонки очереди / в работе / готов (из Laravel или mock).
2. В шапке ввести `Алексей` или `A123BC77` — при включённом мосте появляются результаты Laravel search (нужна auth-сессия к API).
3. При 401 — баннер «Сессия истекла…»; при 403 — «Недостаточно прав…».

## 5. Тесты

```bash
# Один раз: создать БД для Pest
docker compose exec postgres psql -U lastik -c "CREATE DATABASE lastik_test;"
# или: createdb -U lastik lastik_test

./vendor/bin/pest
# CI: .github/workflows/ci.yml (Postgres 16 + Redis + npm build + Pest)
```

Pest использует PostgreSQL `lastik_test` (`phpunit.xml`).

## 6. Типичные проблемы

| Симптом | Что проверить |
|---------|----------------|
| `connection refused :5432` | `docker compose up -d postgres` |
| Seed падает на unique | `migrate:fresh` перед `db:seed` |
| Search всегда 401 | нужен login/session к Laravel; mock Express ≠ Laravel auth |
| Proxy 502 | `php artisan serve` и `VITE_LARAVEL_PROXY` |
| TV пустой на Laravel | сидер создал `TV-*` заказы на Точке Север |
