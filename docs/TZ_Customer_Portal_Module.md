---
type: source
created: 2026-08-05
updated: 2026-08-05
sources: ["[[TZ-Customer-Portal-Module]"]
tags: [tz,portal]
aliases: ["ТЗ Клиентский портал", "Customer Portal TZ"]
title: "Tz Customer Portal Module"
---

# ТЗ для Cursor — Трек 2.3: Клиентский Кабинет / PWA B2B

**Контекст:** репозиторий /Users/ahmed/_PROJECTS/Lastik-main, ветка `feature/module-customer-portal`.
**Готово (Архитектор):** миграция `bookings.customer_id` (FK к customers) + `customer_portal_tokens` (tenant_id, customer_id, token, expires_at) + RLS. Модель `CustomerPortalToken`. Фундамент: `Customer` (физ/юр), `Booking` (post_id/customer_id/start_time/end_time/status), `Post` (посты/слоты).
**Стек:** Laravel 13 + Inertia/Vue 3 + Pinia. Отдельный guard `customer` (по `CustomerPortalToken`, НЕ User).

## Sprint 2.3.1 — Auth & CustomerPortalService (app/Services/Portal/)
- `issueToken(customer_id)`: создать `CustomerPortalToken` (hash token, expires +30d), вернуть plain-тoken (один раз).
- `resolveToken(plain)`: найти по hash, проверить expires_at, вернуть Customer (или 401).
- `bookSlot(customer_id, post_id, start_time, end_time)`: создать `Booking` со статусом PENDING, customer_id. Проверка пересечения слотов (lockForUpdate на bookings по post_id).
- `cancelBooking(id)`: статус CANCELLED.
- `myBookings(customer_id)`: список активных бронирований.
- **Тесты:** tests/Feature/CustomerPortalTest.php — issueToken+resolve, bookSlot создаёт booking, пересечение слотов → 409.

## Sprint 2.3.2 — API (routes/api.php, группа /api/v1/portal, guest + token auth)
- POST /portal/auth/request-token (phone/email → выдача токена, sandbox)
- GET /portal/me (профиль клиента)
- GET /portal/bookings, POST /portal/bookings, DELETE /portal/bookings/{id}
- GET /portal/posts (доступные посты/слоты)
- Контроллеры: app/Http/Controllers/Portal/ + middleware `auth.customer` (guard customer по токену).

## Sprint 2.3.3 — UI (resources/js/Pages/Portal/ — отдельный Inertia entry или поддомен)
- PortalLogin.vue (запрос токена по телефону)
- PortalDashboard.vue (профиль + мои записи)
- PortalBooking.vue (выбор поста/слота, запись на сервис)
- usePortalStore.js (Pinia). Минимальный адаптив (PWA B2B).
- **DoD:** npm run lint + npm run build = 0 errors.

## Sprint 2.3.4 — E2E
- tests/e2e/portal-booking-flow.spec.ts: login по токену → запись на слот → отмена.

## DoD модуля
- [ ] CustomerPortalTest green
- [ ] auth.customer guard работает (токен, не User)
- [ ] UI lint/build 0 errors
- [ ] E2E portal-booking-flow passed
- [ ] php artisan test baseline не сломан
