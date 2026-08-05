---
type: concept
created: 2026-08-05
updated: 2026-08-05
sources: ["[[TECH-DEBT-v1.1.0]]"]
tags: [tech-debt,quality]
aliases: ["Техдолг v1.1.0", "Tech Debt"]
title: "Tech Debt V1.1.0"
---

# Tech Debt & Handover — v1.1.0 (post-P0)

**Branch:** `feature/v11-nested-bom-wms`  
**Status:** Gate P0 closed; acceptance Layers 1–5 GREEN; TV board cache eviction via domain events.

## Closed in this line of work

- RLS enforcement on remaining tenant tables + fiscal receipts context in jobs.
- Acceptance pipeline: Core / TV / CommerceML batch / E2E (`run-acceptance-smoke.sh`).
- Event bus scaffold: `app/Events`, `app/Listeners`, `EventServiceProvider`.
- Immediate TV board invalidation: `OrderStatusChanged` → `InvalidateTvBoardCache` via `DB::afterCommit` (no Redis inside open order transactions).
- float → bcmath in Production / StockBatch / NestedBom (`BcMathDecimal` string API + `StockBatchPrecisionTest`).
- PWA offline queue: cartDraft restore on POS mount/online; sync payload parity (`customer_id` / `bonus_spend`).
- N+1 guards: stock/orders query-count Pest + Product/CashShift eager-load tests.
- Audit hardening: tenant-scoped POS `Rule::exists`, NestedBom recipe index, Loyalty BCMath, draft tenant guard.

## Next implementation (priority)

### 1. float → bcmath (п.2) — CLOSED

Stock/BOM arithmetic migrated to `BcMathDecimal` (`bcAdd`/`bcMul`/`bcComp`/`bcRound` half-up):

- `ProductionService` (recipe cost, composite sale, produceBatch, FIFO estimate)
- `StockBatchService` (ingress / writeOff / reverse / adjust / transferFifo)
- `NestedBomService` (scale / leaf aggregation)

Coverage: `StockBatchPrecisionTest`, `BcMathDecimalTraitTest`, existing Production/NestedBom/FIFO Pest.

### 2. Fitment Data (2.1)

Extend spare-parts catalog:

- Shared `fitment_vehicles` (platform / OEM fitment dictionary)
- Tenant-scoped `fitment_overrides` with PostgreSQL RLS
- API + Autometria UI hooks as needed for catalog search

### 3. Omnichannel (2.2)

Reservation preemption priorities across channels:

- Preferential write-off / release of lower-priority reserves
- Emit `ReservationPreempted` (and related) on the existing event bus
- Listeners: stock / POS / audit side-effects after commit only

## Notes for implementers

- Namespace: `Autometria\`, not `App\`.
- Cache / Redis side-effects from domain mutations: always `DB::afterCommit`.
- Prefer extending `EventServiceProvider::$listen` over ad-hoc `Event::listen` in controllers.
- Keep `./run-acceptance-smoke.sh` GREEN; add focused Pest for each vector above.
