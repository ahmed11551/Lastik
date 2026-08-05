---
type: source
created: 2026-08-05
updated: 2026-08-05
sources: ["[[DELIVERY-PACKAGE]"]
tags: [delivery,sales]
aliases: ["Сдаточный пакет", "Delivery Package"]
title: "Delivery Package"
---

# AUTOMETRIA ERP — Сдаточный пакет v1.x

Платформа шинного центра / автосервиса (бывш. LASTIK). Уровень зрелости: **v1.x — монолитная рабочая система, готовая к сдаче заказчику**.

## Статус сборки (верифицировано 2026-08-02)
- Backend: `php artisan test` → **176 passed / 786 assertions** (PostgreSQL 16).
- Frontend: `npm run lint` (tsc --noEmit) → 0 errors / 0 warnings; `npm run build` → SUCCESS.
- Инфраструктура: Docker Compose (app/fpm + nginx + queue-worker + scheduler + postgres + redis), все контейнеры healthy.

## Матрица соответствия ТЗ (ядро)
| Раздел ТЗ | Статус | Где |
|---|---|---|
| Мультитенантность + RLS | ✅ | `Tenant`, `TenantModel`, миграции `enable_rls_*` |
| Организация / Точки | ✅ | `Tenant`, `Location` |
| Пользователи / Роли / Права | ✅ | `User`, `Role`, `Permission` |
| Покупатели (физ/юр), импорт | ✅ | `Customer`, `CustomerImportController` |
| Автомобили | ✅ | `Vehicle` |
| Товары / Услуги / Категории | ✅ | `ProductService`, `Category`, `Price` |
| Склады / Остатки / Партии | ✅ | `Warehouse`, `Stock`, `StockBatch`, `Reservation` |
| Заказы / Выдача / Резерв | ✅ | `Order`, `OrderItem`, `Issuance` |
| Касса / Смены / Z-отчёт | ✅ | `CashShift`, `CashMovement`, `Payment` |
| Возвраты / Фискализация | ✅ | `Refund`, `FiscalReceipt` |
| Журнал действий (append-only) | ✅ | `AuditLog` + триггер |
| CRM / Лояльность | ✅ | `LoyaltyTransaction`, tiers |
| Регуляторика (ЧЗ, ЕГАИС, Атол) | ✅ | `MarkingValidation`, `MarkingCode`, EGAIS-доки |
| TV-режим | ✅ | `TvBoardController` + страница |
| PWA offline | ✅ | `public/sw.js`, `usePwa`, Dexie offline |
| CommerceML 2.09 / JSON | ✅ | `CommerceMLImport/ExportController`, `JsonExchange` |
| Инвентаризация / документы | ✅ | `InventoryDocument`, FIFO-post |
| Производство / BOM | ✅ | `ProductService.is_composite`, рецептуры |
| Аналитика / KPI | ✅ | `AnalyticsController`, `KpiCalculationService` |

## Реализованные расширения поверх базового WIP (Sprint A→G)
- **A** — TV-Board UI, PWA offline worker, чек-лист приёмки (21 пункт).
- **B** — двусторонняя интеграция CommerceML 2.09 + JSON Exchange API + UI 1С.
- **C** — Deployment Runbook, Architecture Overview, prod-скрипты.
- **D** — документальный складской учёт (инвентаризация, списание, оприходование, FIFO).
- **E** — углублённая регуляторика (Честный Знак выбытие, ЕГАИС вскрытие, теги 1162/1163 Атол).
- **F** — многоскладовость, перемещения `StockTransfer`, филиальные цены.
- **G** — производство, BOM-рецептуры, автосписание сырья, себестоимость ГП.

## Состав пакета
| Артефакт | Назначение |
|---|---|
| [[DELIVERY-ACT|DELIVERY_ACT]] | Акт сдачи-приёмки (подпись заказчика) |
| [[ACCEPTANCE-CHECKLIST|ACCEPTANCE_CHECKLIST]] | 21 пункт приёмки через UI/API |
| [[RELEASE-NOTES-V1-0-0|release-notes-v1.0.0]] | Release Notes ядра + API-спецификация |
| [[ARCHITECTURE-OVERVIEW|ARCHITECTURE_OVERVIEW]] | Архитектурный обзор |
| [[ADR-001-MULTI-TENANCY-STRATEGY|ADR-001-multi-tenancy-strategy]] | ADR по мультитенантности |
| [[DEPLOYMENT-RUNBOOK|DEPLOYMENT_RUNBOOK]] | Runbook развёртывания у заказчика |
| [[ACCEPTANCE-RUN|acceptance-run]] | Сценарии прогона приёмки |

## Greenfield-модули (НЕ входили в базовый WIP, планируются отдельно)
1. 🛒 Закупки (Supplier Orders) — **в разработке (feature/module-purchases)**.
2. 💳 Зарплаты / Payroll (поверх KPI).
3. 🌐 Клиентский Кабинет / PWA B2B.

⚠️ **requires founder action**: вписать реквизиты и подписи в `DELIVERY_ACT.md` перед передачей заказчику.
