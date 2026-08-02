import { test, expect, type Page, type Route } from '@playwright/test'

/**
 * POS Marking (Честный Знак) — MarkingScanModal + cart payload
 */

const MARK = '010460043900001421sN&<3!91800092dGVzdA=='

const MOCK_PRODUCTS = [
  {
    id: 201,
    product_id: 201,
    sku: 'SHOE-MARK-1',
    barcode: '4601111111111',
    name: 'Кроссовки маркированные',
    title: 'Кроссовки маркированные',
    price: 5990,
    available: 5,
    warehouse_id: 1,
    is_marked: true,
    marking_type: 'SHOES',
  },
  {
    id: 202,
    product_id: 202,
    sku: 'OIL-1',
    barcode: '4602222222222',
    name: 'Масло 5W40',
    title: 'Масло 5W40',
    price: 1200,
    available: 20,
    warehouse_id: 1,
    is_marked: false,
  },
]

async function mockApis(page: Page): Promise<{ lastCheckout: { body: Record<string, unknown> | null } }> {
  const lastCheckout = { body: null as Record<string, unknown> | null }

  await page.route('**/api/v1/**', async (route: Route) => {
    const req = route.request()
    const url = req.url()
    const method = req.method()

    if (url.includes('/auth/login') && method === 'POST') {
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          token: 'e2e-marking-token',
          user: {
            id: 7,
            tenant_id: 1,
            name: 'Кассир E2E',
            full_name: 'Кассир E2E',
            email: 'cashier@autometria.test',
            role: 'cashier',
          },
        }),
      })
    }

    if (url.includes('/shifts/current')) {
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: {
            open: true,
            id: 42,
            opened_at: new Date(Date.now() - 3600_000).toISOString(),
            revenue: 0,
            opening_amount: 5000,
            expected_cash: 5000,
            totals: { cash: 0, card: 0, transfer: 0, deposit: 0, inkasso: 0, withdrawal: 0 },
          },
        }),
      })
    }

    if (url.includes('/stock') || url.includes('/products')) {
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: MOCK_PRODUCTS }),
      })
    }

    if (url.includes('/regulatory/marking/verify') && method === 'POST') {
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: {
            valid: true,
            gtin: '04600439000014',
            serial: 'sN&<3!',
            chestny_znak: 'VALID',
            status: 'EMITTED',
          },
        }),
      })
    }

    if (
      (url.includes('/pos/offline-receipts') || url.includes('/pos/checkout')) &&
      method === 'POST'
    ) {
      const body = req.postDataJSON() as Record<string, unknown>
      lastCheckout.body = body
      return route.fulfill({
        status: 201,
        contentType: 'application/json',
        body: JSON.stringify({
          data: { uuid: body?.uuid || 'mark-ok', status: 'COMPLETED', total: 5990 },
        }),
      })
    }

    return route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ data: [] }),
    })
  })

  return { lastCheckout }
}

test.describe('POS marking flow', () => {
  test('pos-marking-flow: scan DataMatrix before cart add', async ({ page }) => {
    const { lastCheckout } = await mockApis(page)

    await page.addInitScript(() => {
      window.print = () => undefined
      localStorage.setItem('autometria_pos_printer_mode', 'browser')
      localStorage.setItem('autometria_token', 'e2e-marking-token')
      localStorage.setItem(
        'autometria_user',
        JSON.stringify({
          id: 7,
          tenant_id: 1,
          name: 'Кассир E2E',
          full_name: 'Кассир E2E',
          email: 'cashier@autometria.test',
          role: 'cashier',
        }),
      )
    })

    await page.goto('/#/pos')
    await expect(page.getByText('Кроссовки маркированные')).toBeVisible({ timeout: 20_000 })

    // Select marked product → modal
    await page.getByRole('button', { name: /Кроссовки маркированные/i }).click()
    await expect(page.getByTestId('marking-scan-modal')).toBeVisible()

    // Emulate scanner wedge
    await page.getByTestId('marking-code-input').fill(MARK)
    await page.getByTestId('marking-confirm').click()

    await expect(page.getByTestId('marking-scan-modal')).toBeHidden({ timeout: 5_000 })
    await expect(page.getByText('Кроссовки маркированные').first()).toBeVisible()

    // Pay cash
    await page.getByRole('button', { name: /Оплата/i }).click()
    await page.getByRole('button', { name: /Подтвердить оплату/i }).click()

    await expect
      .poll(() => lastCheckout.body !== null, { timeout: 15_000 })
      .toBeTruthy()

    const items = (lastCheckout.body?.items as Array<Record<string, unknown>>) || []
    expect(items.length).toBeGreaterThanOrEqual(1)
    expect(String(items[0].marking_code || '')).toBe(MARK)
  })
})
