import { test, expect, type Page, type Route } from '@playwright/test'

const state = { periodId: 801, status: 'DRAFT', gross: 1200, deductions: 200, net: 1000 }

async function mockApis(page: Page): Promise<void> {
  await page.route('**/api/v1/**', async (route: Route) => {
    const request = route.request()
    const url = request.url()
    const method = request.method()
    const json = (data: unknown, status = 200) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify({ data }) })
    if (url.includes('/shifts/current')) return json({ open: true, id: 1, revenue: 0 })
    if (url.match(/\/payroll-periods$/) && method === 'GET') return json([{ id: state.periodId, name: 'Август 2026', period_from: '2026-08-01', period_to: '2026-08-31', status: state.status, total_gross: state.status === 'DRAFT' ? 0 : state.gross, total_deductions: state.status === 'DRAFT' ? 0 : state.deductions, total_net: state.status === 'DRAFT' ? 0 : state.net }])
    if (url.match(/\/payroll-periods$/) && method === 'POST') return json({ id: state.periodId, status: 'DRAFT' }, 201)
    if (url.includes(`/payroll-periods/${state.periodId}/calculate`)) { state.status = 'CALCULATED'; return json({ id: state.periodId, status: state.status, total_gross: state.gross, total_deductions: state.deductions, total_net: state.net }) }
    if (url.includes('/payslips?')) return json([{ id: 901, payroll_period_id: state.periodId, user_id: 7, user_name: 'Сотрудник E2E', status: state.status, gross: state.gross, deductions_total: state.deductions, net: state.net }])
    if (url.includes('/payslips/901')) return json({ id: 901, user_name: 'Сотрудник E2E', status: state.status, gross: state.gross, deductions_total: state.deductions, net: state.net, items: [{ id: 1, type: 'EARNING', label: 'Выработка (KPI)', amount: state.gross }, { id: 2, type: 'DEDUCTION', label: 'Удержание', amount: state.deductions }] })
    return json([])
  })
}

test('payroll-flow: calculate period and verify net salary', async ({ page }) => {
  state.status = 'DRAFT'
  await mockApis(page)
  await page.addInitScript(() => {
    localStorage.setItem('autometria_token', 'e2e-payroll-token')
    localStorage.setItem('autometria_user', JSON.stringify({ id: 7, tenant_id: 1, name: 'Бухгалтер E2E' }))
  })
  await page.goto('/#/payroll')
  await expect(page.getByTestId('payroll-calculate')).toBeVisible({ timeout: 20_000 })
  await page.getByTestId('payroll-calculate').click()
  await expect(page.locator('body')).toContainText('CALCULATED')
  await page.getByRole('button', { name: 'Ведомости' }).click()
  await expect(page.locator('body')).toContainText('1 000,00')
  expect(state.net).toBe(state.gross - state.deductions)
})
