---
type: source
created: 2026-08-05
updated: 2026-08-05
sources: ["[[TZ-Vector4-NestedBom-Wms]"]
tags: [tz,wms,bom]
aliases: ["ТЗ WMS", "Nested BOM TZ"]
title: "Tz Vector4 Nestedbom Wms"
---

# ТЗ для Cursor — v1.1.0 Вектор 4: Углубление Производства (Nested BOM + WMS Light)

**Контекст:** /Users/ahmed/_PROJECTS/Lastik-main, ветка `feature/v11-nested-bom-wms`.
**Готово (Архитектор):** миграции 000038 (Nested BOM) + 000039 (WMS Light) + модели `Wms.php` (StorageCell, SerialNumber, StockBatchCell). RLS включён.

## 4.А Nested BOM (многоуровневые спецификации)
Данные уже позволяют вложенность: `recipe_items.ingredient_id` ссылается на `products_services`, у которого может быть свой `recipes`. Задача — логика сборки.
- **NestedBomService**: рекурсивно развернуть спецификацию до leaf-ингредиентов (товары без своего recipe), суммируя потребность с учётом `yield_quantity` и `waste_percentage` на каждом уровне.
- **Защита от циклов**: при развёртывании проверять `tenants.max_bom_depth` и детектить цикл (полуфабрикат ссылается на себя транзитивно) → `CircularBomException`.
- **ProductionService.produce()**: при сборке готового изделия автоматически списывать полуфабрикаты (через их рецепты → leaf-ингредиенты) с `lockForUpdate` + FIFO, как уже реализовано для одноуровневого BOM. Обновить `is_semi_finished` у полуфабрикатов (имеют recipe).
- API: `POST /api/v1/production/nested-preview` (дерево потребностей), `POST /api/v1/production/orders` (уже есть, расширить на recursive write-off).

## 4.Б WMS Light (адресное хранение + серийный учёт)
- **StorageCellService**: CRUD ячеек (zone/rack/shelf/bin), размещение партии `StockBatchCell` (кол-во в ячейке), перемещение между ячейками.
- **SerialNumberService**: приёмка с серийными номерами (детали/товары), привязка к `stock_batch_id`, статусы IN_STOCK/SOLD/WRITTEN_OFF; при продаже/списании — перевод в SOLD.
- Интеграция с приёмкой (SupplierOrderService.receiveGoods) — опциональное размещение в ячейку + серийники.
- UI: Pages/Production/NestedBomTree.vue (дерево), Pages/Wms/StorageCells.vue (ячейки), Pages/Wms/SerialNumbers.vue. Навигация в App sidebar.
- API: `/api/v1/wms/storage-cells`, `/api/v1/wms/serial-numbers`, `/api/v1/wms/batch-placement`.

## DoD (как обычно)
- php artisan test: новые Pest-тесты (NestedBomTest, WmsLightTest) — 3+ passed каждый.
- npm run lint && npm run build: 0 errors.
- npx playwright: при необходимости e2e-дополнение.
- Защита цикла BOM покрыта тестом (negative scenario).
