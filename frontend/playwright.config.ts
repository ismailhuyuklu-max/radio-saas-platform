import { defineConfig, devices } from '@playwright/test';

/**
 * Playwright E2E config.
 *
 * Uses the system Chrome (channel: 'chrome') so no extra browser download is
 * needed. The webServer builds + serves the production bundle on :4173.
 */
export default defineConfig({
  testDir: './e2e',
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  reporter: process.env.CI ? 'github' : 'list',
  use: {
    baseURL: 'http://localhost:4173',
    trace: 'on-first-retry',
  },
  projects: [
    {
      name: 'chromium',
      // Locally use the installed system Chrome (no download); in CI use the
      // Playwright-managed Chromium installed via `playwright install`.
      use: {
        ...devices['Desktop Chrome'],
        ...(process.env.CI ? {} : { channel: 'chrome' }),
      },
    },
  ],
  webServer: {
    command: 'npm run preview -- --port 4173 --strictPort',
    url: 'http://localhost:4173/login',
    reuseExistingServer: !process.env.CI,
    timeout: 120_000,
  },
});
