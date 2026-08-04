# Tech Debt & Handover — v1.1.0 (post-P0)

**Branch:** `feature/v11-nested-bom-wms`  
**Status:** Gate P0 closed; acceptance Layers 1–5 GREEN; TV board cache eviction via domain events.

## Closed in this line of work

- RLS enforcement on remaining tenant tables + fiscal receipts context in jobs.
- Acceptance pipeline: Core / TV / CommerceML batch / E2E (`run-acceptance-smoke.sh`).
- Event bus scaffold: `app/Events`, `app/Listeners`, `EventServiceProvider`.
- Immediate TV board invalidation: `OrderStatusChanged` → `InvalidateTvBoardCache` via `DB::afterCommit` (no Redis inside open order transactions).

## Next implementation (priority)

### 1. float → bcmath (п.2)

Replace floating-point stock/BOM arithmetic with `bcmath` in:

- `ProductionService` (nested BOM produce / write-off)
- `StockBatchService` (WMS Light batch qty / FIFO)

Vector 4 code is already on this branch — migrate in place; keep Pest coverage for decimal(14,3) quantities.

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
