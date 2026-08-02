import { test, expect, type Page, type Route } from '@playwright/test'

/**
 * POS Refund E2E — RefundModal → POST /pos/refunds (+ offline localRefunds queue)
 */

const MOCK_ORDER = {
  id: 501,
  status: 'created',
  total: 4500,
  order_items: [
    {
      id: 9001,
      product_id: 101,
      type: 'product',
      qty: 1,
      price: 4500,
      discount: 0,
      snapshot: { name: 'Шина 195/65 R15' },
      marking_code: null,
    },
  ],
}

async function mockApis(page: Page): Promise<{ refundBody: { current: Record<string, unknown> | null } }> {
  const refundBody = { current: null as Record<string, unknown> | null }

  await page.route('**/api/v1/**', async (route: Route) => {
    const req = route.request()
    const url = req.url()
    const method = req.method()

    if (url.includes('/auth/login') && method === 'POST') {
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          token: 'e2e-refund-token',
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
        body: JSON.stringify({
          data: [
            {
              id: 101,
              product_id: 101,
              sku: 'TYRE-195-65',
              name: 'Шина 195/65 R15',
              price: 4500,
              available: 12,
              warehouse_id: 1,
            },
          ],
        }),
      })
    }

    if (url.match(/\/orders\/501/) && method === 'GET') {
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          order: MOCK_ORDER,
          items: MOCK_ORDER.order_items,
        }),
      })
    }

    if (url.includes('/pos/refunds') && method === 'POST') {
      refundBody.current = req.postDataJSON() as Record<string, unknown>
      return route.fulfill({
        status: 201,
        contentType: 'application/json',
        body: JSON.stringify({
          data: {
            refund: { id: 77, status: 'completed', total_amount: 4500 },
            fiscal_receipt: { operation: 'sell_refund', status: 'fiscalized' },
          },
        }),
      })
    }

    return route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ data: [] }),
    })
  })

  return { refundBody }
}

async function readLocalRefunds(page: Page): Promise<Record<string, unknown>[]> {
  return page.evaluate(async () => {
    return new Promise((resolve, reject) => {
      const open = indexedDB.open('PosDatabase')
      open.onerror = () => reject(open.error)
      open.onsuccess = () => {
        const db = open.result
        if (!db.objectStoreNames.contains('localRefunds')) {
          resolve([])
          return
        }
        const tx = db.transaction('localRefunds', 'readonly')
        const req = tx.objectStore('localRefunds').getAll()
        req.onsuccess = () => resolve(req.result || [])
        req.onerror = () => reject(req.error)
      }
    })
  })
}

test.describe('POS refund flow', () => {
  test('pos-refund-flow: modal loads order and posts sell_refund', async ({ page }) => {
    const { refundBody } = await mockApis(page)

    await page.addInitScript(() => {
      window.print = () => undefined
    })

    await page.goto('/#/login')
    await page.locator('input[type="email"]').fill('cashier@autometria.test')
    await page.locator('input[type="password"]').fill('password')
    await page.getByRole('button', { name: /войти/i }).click()

    await page.goto('/#/pos')
    await expect(page.getByTestId('pos-refund-open')).toBeVisible({ timeout: 20_000 })
    await page.getByTestId('pos-refund-open').click()
    await expect(page.getByTestId('refund-modal')).toBeVisible()

    await page.getByTestId('refund-order-id').fill('501')
    await page.getByTestId('refund-load-order').click()
    await expect(page.getByTestId('refund-line-9001')).toBeVisible({ timeout: 10_000 })
    await page.getByTestId('refund-reason').fill('Отказ клиента')
    await page.getByTestId('refund-confirm').click()

    await expect
      .poll(() => refundBody.current !== null, { timeout: 15_000 })
      .toBeTruthy()

    expect(refundBody.current?.order_id).toBe(501)
    const items = (refundBody.current?.items as Array<{ order_item_id: number; qty: number }>) || []
    expect(items[0]?.order_item_id).toBe(9001)
    expect(items[0]?.qty).toBe(1)
  })

  test('pos-refund-flow: offline queues localRefunds PENDING_SYNC', async ({ page, context }) => {
    await mockApis(page)
    await page.addInitScript(() => {
      window.print = () => undefined
      localStorage.setItem('autometria_token', 'e2e-refund-token')
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
    await expect(page.getByTestId('pos-refund-open')).toBeVisible({ timeout: 30_000 })

    // Load order while online, then go offline before confirm
    await page.getByTestId('pos-refund-open').click()
    await page.getByTestId('refund-order-id').fill('501')
    await page.getByTestId('refund-load-order').click()
    await expect(page.getByTestId('refund-line-9001')).toBeVisible({ timeout: 10_000 })

    await context.setOffline(true)
    await expect(page.getByText('OFFLINE', { exact: true })).toBeVisible({ timeout: 10_000 })

    await page.getByTestId('refund-confirm').click()

    await expect
      .poll(
        async () => {
          const rows = await readLocalRefunds(page)
          return rows.filter((r) => r.status === 'PENDING_SYNC').length
        },
        { timeout: 15_000 },
      )
      .toBeGreaterThanOrEqual(1)

    const pending = (await readLocalRefunds(page)).filter((r) => r.status === 'PENDING_SYNC')
    expect(Number(pending[0].order_id)).toBe(501)
  })
})
