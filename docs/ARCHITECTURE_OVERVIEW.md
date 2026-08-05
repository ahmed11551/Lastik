---
type: concept
created: 2026-08-05
updated: 2026-08-05
sources: ["[[ARCHITECTURE-OVERVIEW]"]
tags: [architecture,overview]
aliases: ["Архитектура AUTOMETRIA", "Обзор системы"]
title: "Architecture Overview"
---

# AUTOMETRIA ERP / LASTIK — Architecture Overview

Обзор ключевых архитектурных решений ядра для передачи заказчику и сопровождения production.

Детали ADR: [[ADR-001-multi-tenancy-strategy|ADR-001]]. Целевые слои: [[ARCHITECTURE|Архитектура]].

---

## 1. Высокоуровневая схема

```text
┌──────────────┐     HTTPS      ┌─────────────┐   FastCGI    ┌────────────────┐
│  Браузер /   │ ─────────────► │   Nginx     │ ───────────► │  PHP-FPM 8.4   │
│  POS / 1С    │                │  (webserver)│              │  Laravel 13    │
└──────────────┘                └─────────────┘              └───────┬────────┘
                                                                     │
                    ┌────────────────┬────────────────┬──────────────┤
                    ▼                ▼                ▼              ▼
             PostgreSQL 16        Redis 7         queue:work    schedule:work
             + RLS + pg_trgm      cache/queue     (imports…)    (cron loop)
```

- **HTTP API**: `/api/v1/*` (Sanctum / session; обмен 1С — Basic Auth на `/api/v1/1c/exchange`).
- **SPA**: Vue 3 + Pinia (Autometria shell) / отдельные Inertia-страницы (TV Board, Integrations).
- **Лицензирование**: RSA-подпись `autometria.lic`, проверка middleware на каждом запросе (кроме `local`/`testing`).

---

## 2. Multi-tenancy: Single Database, Shared Schema

### Решение

Одна база PostgreSQL, одна схема, изоляция по колонке **`tenant_id`** на всех доменных таблицах.

| Что | Как |
|-----|-----|
| Идентификация тенанта | `users.tenant_id`, middleware `EnsureTenant` |
| Контекст запроса | helper `set_current_tenant_id($id)` |
| Жёсткая изоляция | PostgreSQL **Row Level Security (RLS)** |
| Прикладной scope | `TenantModel` / global scopes как дополнительный слой |

### Почему не database-per-tenant

- Ниже стоимость эксплуатации на self-hosted инсталляциях заказчика.
- Единый пайплайн миграций и бэкапов.
- RLS защищает даже от ошибочных raw-SQL / забытых `where tenant_id`.

### Установка контекста

```php
// app/Support/helpers.php
set_current_tenant_id(?int $tenantId): void
// → app()->instance('current_tenant_id', …)
// → SELECT set_config('app.current_tenant_id', $id, true)  // pgsql session GUC
```

Middleware `EnsureTenant` / `EnforceTenantScope` вызывают helper на каждом API-запросе. Заголовок `X-Tenant-ID` не может переключить чужой тенант.

---

## 3. PostgreSQL Row Level Security (RLS)

### Политика

На tenant-таблицах:

```sql
ALTER TABLE <table> ENABLE ROW LEVEL SECURITY;
ALTER TABLE <table> FORCE ROW LEVEL SECURITY;

CREATE POLICY tenant_isolation_<table> ON <table>
  USING (
    tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::bigint
  );
```

`FORCE` гарантирует применение политики и для владельца таблицы.

### Где включается

| Миграция | Роль |
|----------|------|
| `2026_08_01_000004_enable_rls_for_tenant_scoped_tables` | Базовый набор (orders, stocks, users, …) |
| `2026_08_01_000038_enable_rls_for_all_tenant_tables` | Добор поздних таблиц |
| `2026_08_02_000018_add_production_performance_indexes` | RLS + индексы для fiscal / loyalty / marking / … |

Таблица **`tenants`** и системные (`migrations`, `sessions`, `failed_jobs`, …) — **без** tenant RLS.

### Последствия для эксплуатации

- Любой `psql`-запрос без `set_config('app.current_tenant_id', …)` увидит **0 строк** на защищённых таблицах.
- Фоновые job’ы должны выставлять tenant context перед доступом к данным.
- Тесты — на PostgreSQL; на SQLite RLS-миграции no-op.

### Расширения

При первом старте volume Postgres применяются `docker/postgres/init/01-extensions.sql`:

- `pg_trgm` — поиск / индексы триграмм;
- `uuid-ossp` — UUID-хелперы.

---

## 4. Модель безопасности RBAC

```text
Tenant
  └── Role (permissions: string[])
        └── User (role_id, location_id, devices_limit)
```

- Права — массив slug’ов в `roles.permissions` (например `orders.create`, `stock.import`, `admin.dashboard`).
- Middleware `ensure.permission:<slug>` на маршрутах API.
- `EnforceLocationAccess` ограничивает данные точкой (`location_id`), если нет `locations.all`.
- Device limit: middleware `CheckDeviceLimit` + таблица устройств (ограничение одновременных сессий/касс).

Демо-набор прав сидируется в `AcceptanceSeeder` (роль «Админ» с полным набором для приёмки).

---

## 5. Append-only AuditLog

Таблица `audit_logs` — журнал действий (оплаты, отмены, складские перемещения, KPI, фискализация, импорт клиентов и т.д.).

| Свойство | Реализация |
|----------|------------|
| Запись | `Autometria\Support\AuditLog::write(...)` / модель `Autometria\Models\AuditLog` |
| Изменение / удаление | **запрещены** в модели (`updating` / `deleting` → RuntimeException «append-only») |
| Изоляция | `tenant_id` + RLS |
| Назначение | расследование инцидентов, compliance, разбор кассовых расхождений |

Операторы и SQL-роллбеки «по строкам аудита» не предусмотрены: коррекции бизнес-данных идут отдельными доменными операциями (например correction payment), которые сами пишут новый audit-событие.

---

## 6. Схема изоляции данных (сводка)

```text
┌────────────────────────────────────────────────────────────┐
│ PostgreSQL database                                        │
│  ┌─────────────┐                                           │
│  │ tenants     │  ← без RLS (справочник организаций)       │
│  └─────────────┘                                           │
│  ┌─────────────────────────────────────────────────────┐   │
│  │ Tenant A rows (tenant_id=A)  │  Tenant B rows …     │   │
│  │ orders, stocks, payments,    │  невидимы друг другу │   │
│  │ customers, audit_logs, …     │  при GUC = A         │   │
│  └─────────────────────────────────────────────────────┘   │
└────────────────────────────────────────────────────────────┘
         ▲
         │ set_config('app.current_tenant_id', A)
         │
   Laravel request / queue job
```

**Defense in depth:** RBAC (что можно) → Tenant middleware (чей контекст) → Eloquent scopes → **RLS** (что физически видно в БД) → AuditLog (что произошло).

---

## 7. Интеграции и асинхронность

| Поток | Механизм |
|-------|----------|
| CommerceML import | очередь `imports`, stream parser, `OneCSyncLog` |
| CommerceML / JSON export | синхронные эндпоинты + запись outbound в `OneCSyncLog` |
| Фискализация | jobs + статусы чека |
| POS offline | клиентский Dexie → sync API |

Worker в prod слушает `high,default,imports` — тяжёлый импорт 1С не блокирует короткие job’ы.

---

## 8. Что важно помнить при доработках

1. Новая таблица с `tenant_id` → миграция с `ENABLE` + `FORCE ROW LEVEL SECURITY` + policy.
2. CLI/job без HTTP → явно вызвать `set_current_tenant_id`.
3. Не логировать секреты (пароли 1С, `APP_KEY`, `private.pem`).
4. Не отключать append-only на `audit_logs`.
5. Production-образы собираются из **encoded `dist/`**; исходники и `private.pem` на контур заказчика не попадают.
