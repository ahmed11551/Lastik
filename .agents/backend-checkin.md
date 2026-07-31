# Backend Agent — Check-in Tasks (обязательные к возврату)

## Задача 1
Предложи точную структуру миграций PostgreSQL для FIRST STAGE: tenants, locations, users, roles/permissions, customers, vehicles, products/services, warehouses, stocks, prices, orders, order_items, reservations, payments, payment_corrections, issuances, cash_shifts, cash_movements, kpi_rules, earnings, audit_logs, import_jobs, modules, settings. Важно: tenant_id, RLS-ready, decimal для денег, JSONB там где нужно.

## Задача 2
Напиши Model `Stock` и Service `StockReservationService` с методом `reserve(int $qty)` и `release(int $qty)`. Требуется: pessimistic lock, проверка available, запрет отрицательного available, AuditLog на каждое изменение.

## Задача 3
Напиши миграцию + сервис для загрузки остатков из CommerceML2: обновление actual, невозможность перезаписи активных резервов, запись конфликтов в `stock_conflicts`, запись в AuditLog, статус import_job.

## Задача 4
Реализуй OrderStateMachine для запрещённых переходов: созданный→в работе→готов к выдаче→выдан/выполнен→закрыт и отмена. Правила: нельзя закрыть без оплаты если запрещено настройками, нельзя удалить позицию после оплаты/выдачи, причина отмены обязательна. Snapshot позиции заказа в JSONB.

## Задача 5
Реализуй защищённый API с middleware: Auth + Tenant + Permission. Примеры: `orders.index`, `orders.show`, `payments.correct`, `shifts.close`, `stock.transfer`. Проверка прав по tenant/location/role.

## Задача 6
Предложи структуру модулей в Laravel 13: registry, enable/disable, routes, menu item, settings, permissions, journal integration. Тестовый модуль должен быть включаемым/отключаемым без потери данных.

## Acceptance mapping
Для каждого пункта 49.1-49.8 предложи, как backend гарантирует прохождение без Postman и ручного SQL: endpoints, middleware, проверки, ошибки, которые видит пользователь.
