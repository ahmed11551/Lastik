# CommerceML 2.08 — Stream Parser & Batched Upsert

```
XML Upload → CommerceMLStreamParser (XMLReader)
          → Generator chunks (≤1000 DTOs)
          → CommerceMLBatchUpsertService
              ├─ lockForUpdate reserve check
              ├─ StockConflict audit (actual < reserved)
              └─ PostgreSQL upsert ON CONFLICT (tenant, warehouse, product)
```

## DTOs

- `App\DTOs\CommerceML\CommerceMLProductDTO` — каталог (`Товар`)
- `App\DTOs\CommerceML\StockBalanceDTO` — остатки (`Остаток` / offers)
- Legacy: `CatalogItemDTO` / `CommerceMLUpsertService` (совместимость тестов)

## Services

| Класс | Роль |
|-------|------|
| `CommerceMLStreamParser` | Потоковый XMLReader; Generator без DOM на файлах >500MB |
| `CommerceMLBatchUpsertService` | Chunk 1000, conflict + upsert |
| `CommerceMLImportService` | Оркестрация ImportJob (JSON/XML path) |

## Conflict policy

Если `incoming_actual < reserved`:

1. Резерв **не** сбрасывается.
2. `actual` обновляется.
3. `available = max(0, actual - reserved)`.
4. Пишется `stock_conflicts` + AuditLog.
