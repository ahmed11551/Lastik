---
type: concept
created: 2026-08-05
updated: 2026-08-05
sources: ["[[ADR-001-multi-tenancy-strategy]"]
tags: [adr,architecture,tenancy]
aliases: ["ADR-001", "Мультитенантность"]
title: "Adr 001 Multi Tenancy Strategy"
---

# ADR-001 — Multi-Tenancy Strategy

## Дата
2026-08-01

## Статус
Accepted

## Контекст
Требуется безопасная изоляция данных филиалов шиномонтажа в рамках одной базы LASTIK без предоставления прямого доступа к данным чужих тенантов.

## Решение
Выбрана стратегия **shared database, single schema, PostgreSQL RLS**.

### Причины
- Один экземпляр PostgreSQL снижает операционные затраты.
- RLS позволяет реализовать изоляцию на уровне движка БД, а не только в приложении.
- Это покрывает случай, когда часть запросов будет выполняться не через контроллеры.

## Последствия
- В каждом запросе MUST устанавливаться `tenant_id`.
- Все tenant-scoped таблицы подключаются к RLS.
- Тестовая среда должна использовать PostgreSQL; для SQLite RLS-миграции пропускаются.
- Миграции не должны включать RLS для системных таблиц (`sessions`, `failed_jobs`, `password_reset_tokens`, `migrations`).
