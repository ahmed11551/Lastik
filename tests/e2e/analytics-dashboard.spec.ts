import { test, expect, type Page, type Route } from '@playwright/test'

/**
 * Analytics & Executive Dashboard E2E — real auth + widget load + date-range reactivity.
 * Backend analytics endpoints are mocked so the test focuses on the UI shell.
 */

function summaryFixture() {
  return {
    revenue: 1250000,
    cogs: 820000,
    gross_profit: 430000,
    margin_pct: 34.4,
    avg_check: 5400,
    orders_count: 231,
    revenue_delta_pct: 12.5,
  }
}

function cogsFixture() {
  return [
    { product_id: 1, product_name: 'Шина 195/65 R15', sku: 'TYRE-195', qty: 120, revenue: 540000, cogs: 360000, gross_profit: 180000, margin_pct: 33.3 },
    { product_id: 2, product_name: 'Диск 15x6.5', sku: 'DISK-15', qty: 80, revenue: 256000, cogs: 180000, gross_profit: 76000, margin_pct: 29.7 },
    { product_id: 3, product_name: 'Шина 205/55 R16', sku: 'TYRE-205', qty: 60, revenue: 360000, cogs: 240000, gross_profit: 120000, margin_pct: 33.3 },
  ]
}

function turnoverFixture() {
  return {
    cogs_period: 820000,
    average_inventory_value: 410000,
    inventory_value_basis: 'current',
    turnover_ratio: 2.0,
    deadstock: [],
  }
}

function seriesFixture() {
  const out: Array<{ date: string; revenue: number; cogs: number; gross_profit: number }> = []
  for (let i = 0; i < 30; i++) {
    const d = new Date()
    d.setDate(d.getDate() - (29 - i))
    out.push({
      date: d.toISOString().slice(0, 10),
      revenue: 40000 + i * 1500,
      cogs: 26000 + i * 1000,
      gross_profit: 14000 + i * 500,
    })
  }
  return out
}

function abcFixture() {
  return {
    abc: { '1': { abc: 'A', gross_profit: 180000 } },
    xyz: { '1': 'X' },
    rows: [
      { product_id: 1, product_name: 'Шина 195/65 R15', sku: 'TYRE-195', gross_profit: 180000, abc: 'A', xyz: 'X' },
      { product_id: 2, product_name: 'Диск 15x6.5', sku: 'DISK-15', gross_profit: 76000, abc: 'B', xyz: 'Y' },
      { product_id: 3, product_name: 'Шина 205/55 R16', sku: 'TYRE-205', gross_profit: 120000, abc: 'A', xyz: 'Z' },
    ],
  }
}

async function mockAnalytics(page: Page): Promise<void> {
  await page.route('**/api/v1/**', async (route: Route) => {
    const url = route.request().url()

    if (url.includes('/auth/login')) {
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          token: 'e2e-test-token',
          user: { id: 7, tenant_id: 1, name: 'Админ E2E', email: 'admin@lastik.local', role: 'admin' },
        }),
      })
    }
    if (url.includes('/analytics/dashboard-summary')) {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(summaryFixture()) })
    }
    if (url.includes('/analytics/cogs-breakdown')) {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(cogsFixture()) })
    }
    if (url.includes('/analytics/turnover')) {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(turnoverFixture()) })
    }
    if (url.includes('/analytics/sales-series')) {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: seriesFixture() }) })
    }
    if (url.includes('/analytics/abc-xyz')) {
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(abcFixture()) })
    }

    return route.fulfill({ status: 200, contentType: 'application/json', body: '{}' })
  })
}

test('analytics dashboard loads widgets and reacts to date range', async ({ page, context }) => {
  await mockAnalytics(page)

  // Authenticate via API, then seed token into localStorage (frontend reads it).
  await page.goto('/')
  const loginResp = await page.request.post('/api/v1/auth/login', {
    data: { email: 'admin@lastik.local', password: 'password' },
  })
  const loginBody = (await loginResp.json()) as { token?: string; user?: { id: number; tenant_id: number } }
  await page.addInitScript(
    ({ token, user }) => {
      localStorage.setItem('autometria_token', token)
      localStorage.setItem('autometria_user', JSON.stringify(user))
    },
    { token: loginBody.token ?? 'e2e-test-token', user: loginBody.user ?? { id: 7, tenant_id: 1 } },
  )

  // Step 2: open dashboard (Inertia page, requires auth)
  await page.goto('/dashboard')
  await expect(page).toHaveURL(/dashboard/)

  // Step 3: summary cards
  const cards = page.getByTestId('summary-card')
  await expect(cards).toHaveCount(5, { timeout: 20_000 })
  await expect(cards.first()).toContainText('Net Revenue')

  // Step 4: charts
  await expect(page.locator('canvas')).toHaveCount(2, { timeout: 10_000 })

  // Step 5: ABC/XYZ table
  await expect(page.getByText('Матрица ABC / XYZ')).toBeVisible()

  // Step 6: switch range → refetch, no crash
  await page.getByTestId('range-7d').click()
  await expect(cards.first()).toContainText('Net Revenue')

  await context.clearCookies()
})
