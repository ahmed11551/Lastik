import { test, expect, type Page, type Route } from '@playwright/test'

/**
 * Purchases E2E — create supplier order → confirm → receive → stock grows (mocked API).
 */

const state = {
  orderId: 701,
  status: 'DRAFT',
  available: 2,
  receivedQty: 0,
  qty: 10,
}

async function mockApis(page: Page): Promise<void> {
  await page.route('**/api/v1/**', async (route: Route) => {
    const req = route.request()
    const url = req.url()
    const method = req.method()

    if (url.includes('/auth/login') && method === 'POST') {
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          token: 'e2e-purchases-token',
          user: {
            id: 7,
            tenant_id: 1,
            name: 'Закупщик E2E',
            email: 'buyer@autometria.test',
            role: 'admin',
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
            id: 1,
            opened_at: new Date().toISOString(),
            revenue: 0,
            expected_cash: 0,
            totals: {},
          },
        }),
      })
    }

    if (url.includes('/warehouses')) {
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: [{ id: 1, name: 'Основной склад' }] }),
      })
    }

    if (url.includes('/suppliers') && method === 'GET') {
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: [{ id: 11, name: 'ООО Поставщик', is_active: true }] }),
      })
    }

    if (url.includes('/products')) {
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: [{ id: 101, name: 'Шина 195/65', article: 'TYRE-195', base_price: 100 }],
        }),
      })
    }

    if (url.match(/\/supplier-orders$/) && method === 'GET') {
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: [
            {
              id: state.orderId,
              supplier_id: 11,
              supplier_name: 'ООО Поставщик',
              warehouse_id: 1,
              warehouse_name: 'Основной склад',
              status: state.status,
              total_amount: 1000,
              items: [
                {
                  id: 1,
                  product_id: 101,
                  product_name: 'Шина 195/65',
                  qty: state.qty,
                  received_qty: state.receivedQty,
                  unit_price: 100,
                },
              ],
            },
          ],
        }),
      })
    }

    if (url.match(/\/supplier-orders$/) && method === 'POST') {
      state.status = 'DRAFT'
      state.receivedQty = 0
      const body = req.postDataJSON() as { items?: Array<{ qty: number }> }
      state.qty = Number(body?.items?.[0]?.qty || 10)
      return route.fulfill({
        status: 201,
        contentType: 'application/json',
        body: JSON.stringify({
          data: {
            id: state.orderId,
            supplier_id: 11,
            warehouse_id: 1,
            status: 'DRAFT',
            total_amount: state.qty * 100,
            items: [
              {
                id: 1,
                product_id: 101,
                qty: state.qty,
                received_qty: 0,
                unit_price: 100,
              },
            ],
          },
        }),
      })
    }

    if (url.includes(`/supplier-orders/${state.orderId}/confirm`) && method === 'POST') {
      state.status = 'CONFIRMED'
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: {
            id: state.orderId,
            status: 'CONFIRMED',
            supplier_id: 11,
            warehouse_id: 1,
            items: [
              {
                id: 1,
                product_id: 101,
                qty: state.qty,
                received_qty: 0,
                unit_price: 100,
              },
            ],
          },
        }),
      })
    }

    if (url.includes(`/supplier-orders/${state.orderId}/receive`) && method === 'POST') {
      const body = req.postDataJSON() as { items?: Array<{ qty: number }> }
      const qty = Number(body?.items?.[0]?.qty || 0)
      state.receivedQty += qty
      state.available += qty
      state.status = state.receivedQty >= state.qty ? 'RECEIVED' : 'PARTIALLY_RECEIVED'
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: {
            id: state.orderId,
            status: state.status,
            supplier_id: 11,
            warehouse_id: 1,
            items: [
              {
                id: 1,
                product_id: 101,
                qty: state.qty,
                received_qty: state.receivedQty,
                unit_price: 100,
              },
            ],
          },
        }),
      })
    }

    if (url.includes('/stock')) {
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: [
            {
              product_id: 101,
              name: 'Шина 195/65',
              available: state.available,
              warehouse_id: 1,
              price: 100,
            },
          ],
        }),
      })
    }

    return route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ data: [] }),
    })
  })
}

test.describe('Purchases flow', () => {
  test('purchases-flow: create order → receive goods → stock grows', async ({ page }) => {
    state.available = 2
    state.receivedQty = 0
    state.status = 'DRAFT'
    state.qty = 10

    await mockApis(page)
    await page.addInitScript(() => {
      localStorage.setItem('autometria_token', 'e2e-purchases-token')
      localStorage.setItem(
        'autometria_user',
        JSON.stringify({
          id: 7,
          tenant_id: 1,
          name: 'Закупщик E2E',
          email: 'buyer@autometria.test',
          role: 'admin',
        }),
      )
    })

    await page.goto('/#/purchases')
    await expect(page.getByTestId('purchase-create')).toBeVisible({ timeout: 20_000 })
    await page.getByTestId('purchase-create').click()

    await expect(page.getByTestId('po-supplier')).toBeVisible({ timeout: 10_000 })
    await page.getByTestId('po-supplier').selectOption('11')
    await page.getByTestId('po-warehouse').selectOption('1')
    // Ensure at least one line exists (form seeds one; add as fallback)
    if (!(await page.getByTestId('po-qty').count())) {
      await page.getByTestId('po-add-line').click()
    }
    await page.getByTestId('po-product').selectOption('101')
    await page.getByTestId('po-qty').fill('10')
    await page.getByTestId('po-price').fill('100')
    await page.getByTestId('po-save').click()

    await expect(page.getByTestId('po-status')).toContainText('DRAFT')
    await page.getByTestId('po-confirm').click()
    await expect(page.getByTestId('po-status')).toContainText('CONFIRMED')

    await page.getByTestId('po-receive-qty').fill('7')
    await page.getByTestId('po-receive').click()
    await expect(page.getByTestId('po-status')).toContainText('PARTIALLY_RECEIVED')

    // Stock growth via mocked /stock after receive (2 + 7)
    expect(state.available).toBe(9)
    await page.goto('/#/warehouse')
    // WarehouseView renders name in both mobile card + desktop table; assert attached text + qty
    await expect(page.locator('body')).toContainText('Шина 195/65', { timeout: 15_000 })
    await expect(page.locator('body')).toContainText(String(state.available))
  })
})
