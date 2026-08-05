import { test, expect, type Page, type Route } from '@playwright/test'

/**
 * POS Offline-First E2E — IndexedDB catalog, offline sale, sync on reconnect.
 */

const MOCK_PRODUCTS = [
  {
    id: 101,
    product_id: 101,
    sku: 'TYRE-195-65',
    barcode: '4601234567890',
    name: 'Шина 195/65 R15',
    title: 'Шина 195/65 R15',
    price: 4500,
    available: 12,
    warehouse_id: 1,
  },
  {
    id: 102,
    product_id: 102,
    sku: 'DISK-15-5',
    barcode: '4609876543210',
    name: 'Диск 15x6.5',
    title: 'Диск 15x6.5',
    price: 3200,
    available: 8,
    warehouse_id: 1,
  },
]

async function mockApis(page: Page): Promise<{ syncCalls: { count: number } }> {
  const syncCalls = { count: 0 }

  await page.route('**/api/v1/**', async (route: Route) => {
    const req = route.request()
    const url = req.url()
    const method = req.method()

    if (url.includes('/auth/login') && method === 'POST') {
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          token: 'e2e-test-token',
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

    if (url.includes('/pos/offline-receipts') && method === 'POST') {
      syncCalls.count += 1
      const body = req.postDataJSON() as { uuid?: string }
      return route.fulfill({
        status: 201,
        contentType: 'application/json',
        body: JSON.stringify({
          data: {
            uuid: body?.uuid || 'synced',
            status: 'COMPLETED',
            id: 9001,
          },
        }),
      })
    }

    // Default: empty ok for any other API
    return route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ data: [] }),
    })
  })

  return { syncCalls }
}

async function readIdbStore(
  page: Page,
  storeName: 'cachedProducts' | 'localReceipts',
): Promise<Record<string, unknown>[]> {
  return page.evaluate(async (store) => {
    return new Promise((resolve, reject) => {
      const open = indexedDB.open('PosDatabase')
      open.onerror = () => reject(open.error)
      open.onsuccess = () => {
        const db = open.result
        if (!db.objectStoreNames.contains(store)) {
          resolve([])
          return
        }
        const tx = db.transaction(store, 'readonly')
        const req = tx.objectStore(store).getAll()
        req.onsuccess = () => resolve(req.result || [])
        req.onerror = () => reject(req.error)
      }
    })
  }, storeName)
}

test.describe('POS offline sync flow', () => {
  test('pos-offline-flow: catalog → offline pay → sync', async ({ page, context }) => {
    const { syncCalls } = await mockApis(page)

    await page.addInitScript(() => {
      // Prevent print dialog / hang in headless
      window.print = () => undefined
      localStorage.setItem('autometria_pos_printer_mode', 'browser')
    })

    // Step 1: login as cashier
    await page.goto('/#/login')
    await expect(page.getByRole('heading', { name: /вход|login|авториз/i }).or(page.locator('form')).first()).toBeVisible({
      timeout: 30_000,
    })

    const email = page.locator('input[type="email"], input[name="email"], input[autocomplete="username"]').first()
    const password = page.locator('input[type="password"]').first()
    if (await email.count()) {
      await email.fill('cashier@autometria.test')
      await password.fill('password')
      await page.getByTestId('login-submit').click()
      await page.waitForTimeout(400)
    } else {
      // Fallback: seed session if login form markup differs
      await page.evaluate(() => {
        localStorage.setItem('autometria_token', 'e2e-test-token')
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
    }

    await page.goto('/#/pos')
    await expect(page.getByText(/POS|Каталог|Shift/i).first()).toBeVisible({ timeout: 30_000 })
    await expect(page.getByText('Шина 195/65 R15')).toBeVisible({ timeout: 20_000 })
    await expect(page.getByText('Диск 15x6.5')).toBeVisible()

    // Step 2: catalog cached in IndexedDB
    await expect
      .poll(async () => (await readIdbStore(page, 'cachedProducts')).length, { timeout: 15_000 })
      .toBeGreaterThanOrEqual(2)

    const cached = await readIdbStore(page, 'cachedProducts')
    expect(cached.some((p) => Number(p.product_id) === 101)).toBeTruthy()

    // Step 3: go offline
    await context.setOffline(true)
    await expect(page.getByText('OFFLINE', { exact: true })).toBeVisible({ timeout: 10_000 })

    // Step 4: add 2 products + cash pay
    await page.getByRole('button', { name: /Шина 195\/65 R15/i }).click()
    await page.getByRole('button', { name: /Диск 15x6\.5/i }).click()
    await page.getByRole('button', { name: /Оплата/i }).click()
    await expect(page.getByRole('dialog', { name: /Оплата/i })).toBeVisible()
    await page.getByRole('button', { name: 'Наличные' }).click()
    await page.getByRole('button', { name: /Подтвердить оплату/i }).click()

    // Step 5: OFFLINE indicator + PENDING_SYNC in Dexie
    await expect(page.getByText('OFFLINE', { exact: true })).toBeVisible()
    await expect
      .poll(
        async () => {
          const rows = await readIdbStore(page, 'localReceipts')
          return rows.filter((r) => r.status === 'PENDING_SYNC').length
        },
        { timeout: 15_000 },
      )
      .toBeGreaterThanOrEqual(1)

    const pending = (await readIdbStore(page, 'localReceipts')).filter(
      (r) => r.status === 'PENDING_SYNC',
    )
    expect(pending[0].items).toBeTruthy()
    expect((pending[0].items as unknown[]).length).toBe(2)

    // Step 6–7: restore network → online event triggers sync → SYNCED
    const beforeSync = syncCalls.count
    await context.setOffline(false)
    await expect(page.getByText('ONLINE', { exact: true })).toBeVisible({ timeout: 10_000 })

    // Ensure sync runs (online listener + manual fallback)
    await page.getByRole('button', { name: /^Sync$/i }).click()

    await expect
      .poll(
        async () => {
          const rows = await readIdbStore(page, 'localReceipts')
          return rows.filter((r) => r.status === 'PENDING_SYNC').length
        },
        { timeout: 20_000 },
      )
      .toBe(0)

    const after = await readIdbStore(page, 'localReceipts')
    expect(after.some((r) => r.status === 'SYNCED')).toBeTruthy()
    expect(syncCalls.count).toBeGreaterThan(beforeSync)
  })
})
