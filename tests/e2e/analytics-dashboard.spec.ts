import { test, expect, type Page, type Route } from '@playwright/test'

/**
 * Analytics & Executive Dashboard E2E — SPA hash route + mocked analytics API.
 */

function dashboardFixture() {
  return {
    data: {
      net_revenue: 1250000,
      revenue: 1250000,
      cogs: 820000,
      gross_profit: 430000,
      margin_pct: 34.4,
      avg_check: 5400,
      orders_count: 231,
      revenue_delta_pct: 12.5,
      turnover_rate: 2.0,
      stock_value: 410000,
      top_products: [
        {
          product_id: 1,
          product_name: 'Шина 195/65 R15',
          sku: 'TYRE-195',
          qty: 120,
          revenue: 540000,
          cogs: 360000,
          gross_profit: 180000,
          margin_pct: 33.3,
        },
        {
          product_id: 2,
          product_name: 'Диск 15x6.5',
          sku: 'DISK-15',
          qty: 80,
          revenue: 256000,
          cogs: 180000,
          gross_profit: 76000,
          margin_pct: 29.7,
        },
        {
          product_id: 3,
          product_name: 'Шина 205/55 R16',
          sku: 'TYRE-205',
          qty: 60,
          revenue: 360000,
          cogs: 240000,
          gross_profit: 120000,
          margin_pct: 33.3,
        },
      ],
      abc_xyz: {
        abc: { '1': { abc: 'A', gross_profit: 180000 } },
        xyz: { '1': 'X' },
        rows: [
          { product_id: 1, product_name: 'Шина 195/65 R15', sku: 'TYRE-195', gross_profit: 180000, abc: 'A', xyz: 'X' },
          { product_id: 2, product_name: 'Диск 15x6.5', sku: 'DISK-15', gross_profit: 76000, abc: 'B', xyz: 'Y' },
          { product_id: 3, product_name: 'Шина 205/55 R16', sku: 'TYRE-205', gross_profit: 120000, abc: 'A', xyz: 'Z' },
        ],
      },
      deadstock: [],
    },
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
  return { data: out }
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
    if (url.includes('/analytics/dashboard')) {
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(dashboardFixture()),
      })
    }
    if (url.includes('/analytics/sales-series')) {
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(seriesFixture()),
      })
    }

    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: [] }) })
  })
}

test('analytics dashboard loads widgets and reacts to date range', async ({ page }) => {
  await mockAnalytics(page)

  await page.addInitScript(() => {
    localStorage.setItem('autometria_token', 'e2e-test-token')
    localStorage.setItem(
      'autometria_user',
      JSON.stringify({ id: 7, tenant_id: 1, name: 'Админ E2E', email: 'admin@lastik.local', role: 'admin' }),
    )
  })

  await page.goto('/#/analytics')
  await expect(page).toHaveURL(/#\/analytics/)

  const cards = page.getByTestId('summary-card')
  await expect(cards).toHaveCount(5, { timeout: 20_000 })
  await expect(cards.first()).toContainText('Net Revenue')

  await expect(page.locator('canvas')).toHaveCount(2, { timeout: 10_000 })
  await expect(page.getByText('Матрица ABC / XYZ')).toBeVisible()

  await page.getByTestId('range-7d').click()
  await expect(cards.first()).toContainText('Net Revenue')
})
