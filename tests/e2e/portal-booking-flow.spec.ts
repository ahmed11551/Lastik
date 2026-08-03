import { expect, test, type Page } from '@playwright/test'

async function mockPortalApi(page: Page): Promise<void> {
  let booking = null as null | { id: number; post: { name: string }; status: string; start_time: string }

  await page.route('**/api/v1/portal/**', async (route) => {
    const url = route.request().url()
    const method = route.request().method()
    const json = (data: unknown, status = 200) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(data) })

    if (url.includes('/auth/request-token') && method === 'POST') return json({ token: 'portal-e2e-token', customer: { id: 1, name: 'Клиент E2E' } })
    if (url.endsWith('/me')) return json({ data: { id: 1, name: 'Клиент E2E', phone: '+79990000000' } })
    if (url.endsWith('/posts')) return json({ data: [{ id: 10, name: 'Пост 1', is_active: true }] })
    if (url.endsWith('/bookings') && method === 'GET') return json({ data: booking ? [booking] : [] })
    if (url.endsWith('/bookings') && method === 'POST') {
      booking = { id: 101, post: { name: 'Пост 1' }, status: 'pending', start_time: '2026-08-20T10:00:00' }
      return json({ data: booking }, 201)
    }
    if (url.includes('/bookings/101') && method === 'DELETE') {
      booking = { ...booking!, status: 'cancelled' }
      return json({ data: booking })
    }
    return json({ data: [] })
  })
}

test('portal booking flow: login, book, cancel', async ({ page }) => {
  await mockPortalApi(page)
  await page.goto('/portal.html#/login')
  await page.getByTestId('portal-phone').fill('+79990000000')
  await page.getByTestId('portal-login').click()
  await expect(page.getByText('Здравствуйте, Клиент E2E')).toBeVisible()

  await page.getByTestId('portal-start-booking').click()
  await page.getByTestId('portal-post').selectOption('10')
  await page.getByTestId('portal-start').fill('2026-08-20T10:00')
  await page.getByTestId('portal-end').fill('2026-08-20T10:30')
  await page.getByTestId('portal-submit-booking').click()
  await expect(page.getByTestId('portal-booking')).toContainText('Пост 1')
  await page.getByTestId('portal-cancel').click()
  await expect(page.getByTestId('portal-booking')).toContainText('cancelled')
})
