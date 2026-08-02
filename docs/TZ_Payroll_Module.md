# ТЗ для Cursor — Трек 2.2: Модуль Зарплат (Payroll)

**Контекст:** репозиторий /Users/ahmed/_PROJECTS/Lastik-main, ветка `feature/module-payroll`.
**Готово (Архитектор):** миграции + модели `PayrollPeriod`, `Payslip`, `PayslipItem`, `Deduction`, `AccrualRule` (наследуют `TenantModel`, RLS включён). Фундамент KPI: `KpiRule`, `Earning` (order_id/user_id/amount/percent/rule_snapshot/source).
**Стек:** Laravel 13 + Inertia/Vue 3 + Pinia + design-system (DsTable/DsModal/DsInput/DsButton). RBAC: middleware `ensure.permission:payroll.*`.

## Sprint 2.2.1 — PayrollService (app/Services/Payroll/)
- `createPeriod(tenant_id, name, from, to)`: `PayrollPeriod` status DRAFT.
- `calculate(period_id)`:
  - Для каждого `User` тенанта собрать `Earning` за период (sum amount) → база начислений.
  - Применить `AccrualRule` (KPI_PERCENT → % от базы; FIXED → сумма; BONUS → из Earning.source).
  - Применить `Deduction` (FIXED → вычет; PERCENT → % от gross).
  - Создать `Payslip` + `PayslipItem[]` (EARNING/DEDUCTION), пересчитать period totals. Всё в `DB::transaction()` + `lockForUpdate()` на payslips.
  - period.status = CALCULATED; AuditLog `payroll.calculated`.
- `approve(period_id)`: status APPROVED (только после CALCULATED).
- `markPaid(period_id)`: status PAID + paid_at; AuditLog `payroll.paid`.
- **Тесты:** tests/Feature/PayrollTest.php — calculate начисляет KPI+бонусы, deduction уменьшает net, approve/paid переходы.

## Sprint 2.2.2 — API (routes/api.php, группа /api/v1/payroll, auth:sanctum + ensure.permission)
- GET /payroll-periods, POST /payroll-periods
- POST /payroll-periods/{id}/calculate
- POST /payroll-periods/{id}/approve
- POST /payroll-periods/{id}/pay
- GET /payslips?period_id=, GET /payslips/{id}
- GET /deductions, POST /deductions (CRUD шаблонов)
- GET /accrual-rules, POST /accrual-rules
- Контроллеры: app/Http/Controllers/Payroll/.

## Sprint 2.2.3 — UI (resources/js/Pages/Payroll/)
- PeriodsIndex.vue (список периодов, статусы, кнопки Calculate/Approve/Pay)
- PayslipView.vue (ведомость: строки EARNING/DEDUCTION, итоги gross/net)
- DeductionRules.vue / AccrualRules.vue (CRUD шаблонов)
- usePayrollStore.js (Pinia). AutometriaLayout, DsTable.
- **DoD:** npm run lint + npm run build = 0 errors.

## Sprint 2.2.4 — E2E
- tests/e2e/payroll-flow.spec.ts: создать период → calculate → проверить payslip net = gross − deductions.

## DoD модуля
- [ ] PayrollTest green
- [ ] API RBAC проверен
- [ ] UI lint/build 0 errors
- [ ] E2E payroll-flow passed
- [ ] php artisan test baseline не сломан
