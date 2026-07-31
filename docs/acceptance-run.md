# Acceptance Run Guide — LASTIK (п. 49.1–49.21)

Пошаговый запуск приёмочного контура: Docker → Postgres/Redis → миграции → сидер → Pest → smoke UI.

## 0. Требования

- Docker Desktop (Postgres 16 + Redis 7)
- PHP 8.3+, Composer
- Node.js 20+ (Vite / React)

## 1. Инфраструктура

```bash
cd /path/to/Lastik-main
docker compose up -d postgres redis
# полный стек (app + nginx + queue-worker + scheduler):
# docker compose up -d
```

Healthcheck:

- Postgres: `pg_isready -U lastik -d lastik`
- Redis: `redis-cli ping`

Сервисы compose:

| Сервис | Назначение |
|--------|------------|
| `postgres` | СУБД + RLS |
| `redis` | cache / queue |
| `app` | PHP-FPM / приложение |
| `queue-worker` | `php artisan queue:work` |
| `scheduler` | `php artisan schedule:work` |
| `webserver` | nginx |

## 2. Backend: env, миграции, сидер

```bash
cp .env.example .env
composer install
php artisan key:generate
# DB_* → lastik / secret @ 127.0.0.1:5432

php artisan migrate:fresh --force
php artisan db:seed --class=AcceptanceSeeder
```

Тестовый env (Pest): `.env.testing` → `DB_DATABASE=lastik_test`.

```bash
docker compose exec postgres psql -U lastik -c "CREATE DATABASE lastik_test;"
./vendor/bin/pest
```

### Учётные данные (пароль: `password`)

| Роль | Email |
|------|-------|
| Owner | `owner@lastik.local` |
| Admin | `admin@lastik.local` |
| Seller | `seller@lastik.local` |
| Cashier | `cashier@lastik.local` |
| Master | `master@lastik.local` |
| Warehouse | `warehouse@lastik.local` |

## 3. Карта acceptance 49.1–49.21

| № | Сценарий | Как проверить | Артефакт |
|---|----------|---------------|----------|
| **49.1** | Tenant isolation | Два fixture-тенанта; заказ A не виден в контексте B; RLS + `BelongsToTenant` | `AcceptanceTenantOrderTest`, `SecurityTest` |
| **49.2** | Device limit | Превышение `devices_limit` → 429 | `DeviceLimitTest` |
| **49.3** | Prices / reserve / stock | Создание заказа резервирует stock под `lockForUpdate`; цена только из `prices` | `OrderStoreTest`, `StockReserveRaceTest` |
| **49.4** | Reservations / release | Отмена / снятие позиции → release; unique `(tenant_id, order_item_id, stock_id, status)` | `AcceptanceIssuanceTest`, миграция `000040` |
| **49.5** | Payments + mixed | Несколько `parts` (cash/card/transfer); `accept()` только на открытой смене | `AcceptanceCashShiftPaymentTest` |
| **49.6** | Shifts + reports | `close()` в транзакции + `lockForUpdate`; totals = сумма платежей | `ShiftTotalsMatchTest`, `ShiftManagementTest` |
| **49.7** | KPI snapshots | `kpi_percent`/`kpi_amount` и `snapshot.kpi_rule` не меняются после правки `kpi_rules` | `OrderItemSnapshotTest`, `AcceptanceKpiTest` |
| **49.8** | Audit log append-only | UPDATE/DELETE `audit_logs` запрещены (модель + PG trigger) | `AcceptanceAuditLogTest`, миграция `000039` |
| **49.9** | Location isolation | Листинг заказов/смен фильтруется `location_id`; чужая точка → 403 | `AcceptanceLocationIsolationTest`, `EnforceLocationAccess` |
| **49.10** | Price list / discount | Цена из `prices.amount`; `items.*.price` prohibited; discount в snapshot | `AcceptancePriceDiscountTest` |
| **49.11** | Payment correction guard | `PaymentService::correct()` → `ShiftAlreadyClosedException` если смена закрыта | `AcceptanceCashShiftPaymentTest` |
| **49.12** | Order cancel | `POST /orders/{id}/cancel` + причина; release резервов | `OrderController::cancel`, lifecycle tests |
| **49.13** | Issuance / fulfill | Списание `actual` через `OrderFulfillmentService` + lock | `AcceptanceIssuanceTest` |
| **49.14** | Stock transfer | Перемещение между складами, conflicts | `AcceptanceStockTransferTest` |
| **49.15** | CommerceML import | Upsert товаров/остатков; conflict → `stock_conflicts` | `CommerceMLUpsertTest`, `AcceptanceCommerceMLConflictTest` |
| **49.16** | Customer merge | Merge под lock; изоляция tenant | `AcceptanceCustomerMergeTest` |
| **49.17** | Tasks | Создание / complete / cancel в tenant-контексте | `AcceptanceTaskTest` |
| **49.18** | Search (п.36) | `GET /api/v1/search?q=` клиент / авто / заказ | `AcceptanceSearchTest` |
| **49.19** | TV board (п.42) | `GET /api/v1/tv/board` колонки queue/work/ready | `AcceptanceTvBoardTest` |
| **49.20** | Bookings | Overlap → `SlotAlreadyBookedException`; изоляция tenant | `BookingTest` |
| **49.21** | Modules enable/disable | `ModuleController` без потери settings JSON | `Module` + AcceptanceSeeder demo_module |

### Команды по группам

```bash
# Tenant / location / security
./vendor/bin/pest tests/Feature/AcceptanceTenantOrderTest.php tests/Feature/AcceptanceLocationIsolationTest.php tests/Feature/SecurityTest.php

# Orders / stock / prices
./vendor/bin/pest tests/Feature/OrderStoreTest.php tests/Feature/OrderItemSnapshotTest.php tests/Feature/AcceptancePriceDiscountTest.php

# Cash / payments / shifts
./vendor/bin/pest tests/Feature/AcceptanceCashShiftPaymentTest.php tests/Feature/PaymentAfterShiftCloseTest.php tests/Feature/ShiftManagementTest.php

# Import / transfer / issuance
./vendor/bin/pest tests/Feature/CommerceMLUpsertTest.php tests/Feature/AcceptanceStockTransferTest.php tests/Feature/AcceptanceIssuanceTest.php

# Полный прогон
./vendor/bin/pest
```

## 4. Laravel API smoke

```bash
php artisan serve --host=127.0.0.1 --port=8000
```

```bash
# login вне tenant middleware
curl -s -X POST http://127.0.0.1:8000/api/v1/auth/login \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json' \
  -d '{"email":"admin@lastik.local","password":"password"}'
```

Защищённые маршруты требуют Sanctum token + контекст пользователя (`tenant_id` из auth; `X-Location-ID` только если совпадает с tenant).

| Метод | Путь | Примечание |
|-------|------|------------|
| GET | `/api/v1/orders` | фильтр tenant + location |
| POST | `/api/v1/orders` | `tenant_id`/`location_id`/`items.*.price` prohibited |
| POST | `/api/v1/payments` | только открытая смена |
| POST | `/api/v1/payments/{id}/correct` | 409 если смена закрыта |
| GET/POST | `/api/v1/shifts` | index строго по tenant+location |
| GET | `/api/v1/search?q=` | п.36 |
| GET | `/api/v1/tv/board` | п.42 |

## 5. Frontend (React bridge)

```bash
# .env
VITE_API_BASE=/api/v1
VITE_LARAVEL_PROXY=http://127.0.0.1:8000

npm install && npm run dev
```

Checklist: TV-колонки, поиск в шапке, баннеры 401/403.

## 6. CI

`.github/workflows/ci.yml` — PHP 8.4, Postgres 16, Redis 7, `npm run build`, Pest.

## 7. Типичные проблемы

| Симптом | Что проверить |
|---------|----------------|
| `connection refused :5432` | `docker compose up -d postgres` |
| Pest падает на RLS | `.env.testing` + `set_current_tenant_id()` |
| `items.*.price` 422 | цена только из `prices`; не передавать `price` в body |
| 409 на correction | смена уже закрыта — ожидаемо |
| Proxy 502 | `php artisan serve` и `VITE_LARAVEL_PROXY` |
