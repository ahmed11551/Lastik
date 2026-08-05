---
type: source
created: 2026-08-05
updated: 2026-08-05
sources: ["[[release-notes-v1.0.0]"]
tags: [release,notes]
aliases: ["Релиз v1.0.0", "Release Notes"]
title: "Release Notes V1.0.0"
---

# LASTIK Release Notes v1.0.0 — Production Core

LASTIK B2B SaaS Engine верифицирован для продакшен-развёртывания.  
Тестовая сюита (PostgreSQL 16): **62 passed / 246 assertions**.

## Key Security & Isolation Hardening

- **Zero-Trust Multi-Tenancy:** `FORCE ROW LEVEL SECURITY` на доменных таблицах. `tenant_id` / `location_id` только из Sanctum-сессии (`auth()->user()`). Payload-поля запрещены (`prohibited` в FormRequest / DTO).
- **Location Scope Validation:** `EnforceLocationAccess` проверяет принадлежность `X-Location-ID` текущему tenant.
- **Global Scope Leak Prevention:** контроллеры не используют `withoutGlobalScopes()` для выборок заказов/смен/платежей.

## Concurrency & Financial Integrity

- **Immutable Catalog Pricing:** `OrderService::lookupCatalogPrice()` — цена только из `prices`.
- **Cash Shift Lockdown:** `PaymentService::correct()` / `CashShiftService::close()` — `DB::transaction` + `lockForUpdate()`; закрытая смена → `ShiftAlreadyClosedException` (HTTP 409).
- **Idempotent Reservations:** unique `(tenant_id, order_item_id, stock_id, status)`.

## Docker / QA

- `queue-worker` (`php artisan queue:work`), `scheduler` (`php artisan schedule:work`)
- Redis healthcheck, `.env.testing` → `lastik_test`
- Acceptance map 49.1–49.21: [acceptance-run.md](./acceptance-run.md)

---

# Core API Integration Specification

## 1. Authentication & Context Headers

Каждый API-запрос (кроме `POST /api/v1/auth/login`) требует Bearer-токен и контекст локации:

```http
Authorization: Bearer <sanctum_token>
X-Location-ID: 12
Content-Type: application/json
Accept: application/json
```

`tenant_id` **не** передаётся в body и **не** переопределяется заголовком `X-Tenant-ID` (mismatch → 403).

## 2. Order Management

### `POST /api/v1/orders`

Поля `price`, `tenant_id`, `location_id` **запрещены**. Цена берётся из каталога `prices`.

```json
{
  "customer_id": 451,
  "assigned_seller_id": 18,
  "master_id": 22,
  "scenario": "without_installation",
  "note": "Замена масла и фильтров",
  "items": [
    { "type": "product", "product_id": 1089, "qty": 4, "warehouse_id": 3 },
    { "type": "service", "product_id": 2045, "qty": 1, "worker_id": 22 }
  ]
}
```

**201 Created** — объект заказа в `data` (`id`, `number`, `status`, `total`, …).

## 3. Cash Shift Management

### `POST /api/v1/shifts/{shift}/close`

Закрытие смены под `lockForUpdate`.

**200 OK:**

```json
{
  "data": {
    "id": 142,
    "location_id": 12,
    "closed_at": "2026-07-31T23:14:22Z",
    "status": "closed",
    "totals": { "cash": 12000, "card": 33200 }
  }
}
```

**409 Conflict** (уже закрыта):

```json
{
  "message": "Кассовая смена уже закрыта. Операция запрещена."
}
```

## 4. Payments

### `POST /api/v1/payments`

```json
{
  "order_id": 9823,
  "parts": [
    { "method": "cash", "amount": 2000 },
    { "method": "card", "amount": 2800 }
  ]
}
```

### `POST /api/v1/payments/{payment}/correct`

Только на **открытой** смене. После `closed_at` → 409 `ShiftAlreadyClosedException`.

## 5. CommerceML Import

### `POST /api/v1/imports/commerceml`

`multipart/form-data` с полем `file` (XML/JSON/ZIP, ≤50MB).

Архитектура: XMLReader stream → batch DTO (1000) → `ON CONFLICT` upsert + `lockForUpdate` reserve check → `stock_conflicts` при `actual < reserved`.

См. также: [CommerceML batch architecture](./commerceml-batch-architecture.md).

---

## 6. История расширений (Sprint A → G)

Ядро v1.0.0 было дополнено модулями без переделки базовой архитектуры:

- **Sprint A — Финализация под приёмку:** TV-Board UI-дашборд, PWA offline service worker, чек-лист приёмки (21 пункт), ErrorBoundary, NetworkStatus.
- **Sprint B — Интеграции:** двусторонняя CommerceML 2.09 (export), JSON Exchange API, UI настроек 1С.
- **Sprint C — Документация/Deploy:** Deployment Runbook, Architecture Overview, prod-скрипты.
- **Sprint D — Складские документы:** инвентаризация, оприходование/списание, FIFO-post, AuditLog на документы.
- **Sprint E — Регуляторика (углублённая):** Честный Знак (выбытие кодов), ЕГАИС (вскрытие тары), теги 1162/1163 Атол, POS-верификация марок.
- **Sprint F — Многоскладовость:** `StockTransfer` (перемещения с FIFO), филиальные цены `WarehouseProductPrice`, CRUD филиалов.
- **Sprint G — Производство/BOM:** `ProductService.is_composite`, рецептуры ТТК, автосписание сырья при выпуске, расчёт себестоимости ГП.

## 7. Статус v1.x (верифицировано 2026-08-02)

- **Backend:** `php artisan test` → **176 passed / 786 assertions** (PostgreSQL 16).
- **Frontend:** `npm run lint` (tsc --noEmit) → 0 errors / 0 warnings; `npm run build` → SUCCESS.
- **Инфраструктура:** Docker Compose (app/fpm + nginx + queue-worker + scheduler + postgres + redis), все контейнеры healthy.
- **Сдаточный пакет:** [DELIVERY_PACKAGE.md](./DELIVERY_PACKAGE.md), Акт [DELIVERY_ACT.md](./DELIVERY_ACT.md).

## 8. Greenfield-дорожная карта (отдельные модули)

1. 🛒 **Закупки (Supplier Orders)** — в разработке (`feature/module-purchases`): заказы поставщикам, приход партиями в `StockBatch`, планирование пополнения по min/max.
2. 💳 **Зарплаты / Payroll** — ведомости, удержания, сетка начислений поверх `KpiRule`/`Earning`.
3. 🌐 **Клиентский Кабинет / PWA B2B** — портал оптовых клиентов + запись на сервис.
