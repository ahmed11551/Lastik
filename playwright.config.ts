import { defineConfig, devices } from '@playwright/test'

/**
 * AUTOMETRIA ERP — Playwright E2E (POS Offline-First)
 */
export default defineConfig({
  testDir: 'tests/e2e',
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  workers: 1,
  reporter: [['list']],
  timeout: 60_000,
  use: {
    baseURL: process.env.PLAYWRIGHT_BASE_URL || 'http://127.0.0.1:5178',
    trace: 'on-first-retry',
    ...devices['Desktop Chrome'],
    channel: process.env.PLAYWRIGHT_CHANNEL || 'chrome',
  },
  webServer: {
    command: 'npm run dev -- --host 127.0.0.1 --port 5178',
    url: 'http://127.0.0.1:5178',
    reuseExistingServer: !process.env.CI,
    timeout: 120_000,
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
})
