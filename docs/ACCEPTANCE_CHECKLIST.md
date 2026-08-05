---
type: source
created: 2026-08-05
updated: 2026-08-05
sources: ["[[ACCEPTANCE-CHECKLIST]"]
tags: [acceptance,qa]
aliases: ["Чек-лист приёмки", "Acceptance Checklist"]
title: "Acceptance Checklist"
---

# AUTOMETRIA ERP/POS — Acceptance Checklist (21 пунктов ТЗ)

Чек-лист UI/API-приёмки Sprint A. Отмечать `[x]` после проверки на стенде.

## A. Кассовый контур (POS)

1. [ ] **Создание заказа** — заказ с товаром/услугой создаётся из UI (`#/orders` или POS), номер присвоен, статус начальный.
2. [ ] **Резерв склада** — при создании заказа с товаром `stocks.available` уменьшается / `reserved` растёт; недостаточно остатка → 422.
3. [ ] **Выдача (issuance)** — проведение выдачи фиксирует списание; AuditLog `issuance.created`.
4. [ ] **Оплата** — cash/card через POS/кассу; `payment_status=paid`; AuditLog `payment.created`.
5. [ ] **Фискализация** — создаётся `fiscal_receipts` (или NullFiscalDriver в test); статус не «завис» в pending без причины.
6. [x] **Offline-черновик чека** — при обрыве сети корзина POS сохраняется в IndexedDB (`cartDrafts`); индикатор NetworkStatus/offline виден; restore на mount / online.
7. [x] **Offline sync** — после восстановления сети очередь `localReceipts` уходит в `POST /api/v1/pos/offline-receipts` с `X-Idempotency-Key` (+ `customer_id` / `bonus_spend`).

## B. Смена и KPI

8. [ ] **Открытие смены** — `cash_shift.open` в AuditLog; виджет смены показывает «открыта».
9. [ ] **Закрытие смены (Z-отчёт)** — `cash_shift.close`; итоги смены сходятся с суммой payments.
10. [ ] **Расчёт KPI** — после оплаты услуг начисляется KPI; AuditLog `kpi.order.earned` / `kpi.item.earned`; экран `#/kpi` отображает суммы.

## C. Склад и финансы

11. [ ] **FIFO write-off** — продажа товара списывает партии `ORDER BY received_at ASC`; COGS в analytics корректен.
12. [ ] **AuditLog на каждое действие** — create order / reserve / issue / pay / close shift / KPI имеют записи в `GET /api/v1/audit-logs` (immutable).
13. [ ] **RLS изоляция** — пользователь тенанта B не видит заказы/чеки тенанта A (API + UI).

## D. TV Board & PWA

14. [ ] **TV Board UI** — `#/tv_display` или `/tv-board` показывает 3 колонки: Очередь / В работе / Готово.
15. [ ] **TV Board API** — `GET /api/v1/tv/board` возвращает `data.columns.{queue,in_progress,ready}`; polling ~15с обновляет UI.
16. [x] **PWA Service Worker** — `/sw.js` зарегистрирован; статика shell в Cache Storage; `/api/*` не кэшируется.
17. [ ] **ErrorBoundary** — искусственная ошибка Vue не даёт White Screen of Death; toast/UI recovery.
18. [x] **NetworkStatus** — offline → индикатор; online → восстановление (+ sync flush / cart draft restore).

## E. Сборка и регрессия

19. [ ] **Backend suite** — `php artisan test` → PASS.
20. [ ] **Frontend** — `npm run lint && npm run build` → LINT_EXIT=0, BUILD_EXIT=0.
21. [ ] **E2E smoke** — `npx playwright test` (pos-offline / pos-marking / analytics) без критических падений; TV Board smoke при наличии спека.

---

### Быстрый прогон цепочки (happy path)

```text
Login → Open Shift → Create Order (product) → Reserve OK
→ Issuance → POS Pay → Fiscal/Payment OK → Close Shift (Z)
→ KPI screen → Audit log contains all actions → TV Board shows ready/issued
```

### Связанные артефакты

| Артефакт | Путь |
|----------|------|
| TV Board page | `resources/js/Pages/TvBoard/Index.vue` |
| PWA composable | `resources/js/autometria/composables/usePwa.ts` |
| Service Worker | `public/sw.js` |
| Offline drafts | `useOfflineStore.saveCartDraft` / Dexie `cartDrafts` |
| TV API | `GET /api/v1/tv/board` → `TvBoardController` |
| Audit API | `GET /api/v1/audit-logs` |
