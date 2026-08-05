---
type: concept
created: 2026-08-05
updated: 2026-08-05
sources: ["[[ARCHITECTURE]"]
tags: [architecture,backend]
aliases: ["Архитектура", "Стек Laravel PostgreSQL"]
title: "Architecture"
---

# LASTIK — Architecture

## Назначение документа
`ARCHITECTURE.md` описывает целевое enterprise-устройство проекта LASTIK: изоляцию тенантов, слой сервисов, поток данных и роли компонентов.

## 1. Слои приложения
- **HTTP/Presentation** — контроллеры, Inertia/Vue 3 frontend, Form Requests.
- **Application** — DTO, сервисный слой, координация транзакций.
- **Domain** — Enums, доменные исключения, бизнес-правила изоляции и списаний.
- **Infrastructure** — миграции PostgreSQL, RLS, репозитории Eloquent.

## 2. Мультитенантность и RLS
- Все доменные таблицы содержат `tenant_id`.
- Изоляция реализуется на уровне PostgreSQL RLS:
  - `current_setting('app.current_tenant_id')::bigint`
  - политики включаются только для PostgreSQL.
- Сервисы и контроллеры никогда не берут на себя фильтрацию тенанта вручную: RLS — основной источник изоляции.

## 3. Сервисный слой
- `StockReservationService` — резерв остатков через `lockForUpdate()`.
- `KpiService` — расчет сдельной оплаты мастеров в рамках смены.
- `ShiftManagementService` — открытие/закрытие смены с сверкой кассы.

## 4. Требования к данным
- Типы в DTO строго типизированы.
- Доменные исключения отвечают за явные ошибки бизнеса: `InsufficientStockException`, `TenantAccessDeniedException`.
