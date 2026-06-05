import { expect, test } from '@playwright/test';

/**
 * Public login-page smoke E2E (no backend required).
 * Verifies the SPA boots, the splash is replaced, and the login form renders.
 */
test.describe('Login page', () => {
  test('renders the brand and login form', async ({ page }) => {
    await page.goto('/login');

    // Brand
    await expect(page.getByRole('heading', { name: 'Aircast Pro' })).toBeVisible();

    // Form fields + submit
    await expect(page.getByPlaceholder('admin')).toBeVisible();
    await expect(page.locator('input[type="password"]')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Giriş Yap' })).toBeVisible();
  });

  test('validates empty submit without navigating away', async ({ page }) => {
    await page.goto('/login');
    await page.getByRole('button', { name: 'Giriş Yap' }).click();
    // Stays on /login (warning toast shown, no redirect)
    await expect(page).toHaveURL(/\/login$/);
  });

  test('unauthenticated deep link redirects to login', async ({ page }) => {
    await page.goto('/radio-platform/operations');
    await expect(page).toHaveURL(/\/login/);
  });
});
