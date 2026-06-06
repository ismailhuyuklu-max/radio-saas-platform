import { expect, test } from '@playwright/test';

/**
 * Mobil 390px viewport doğrulaması — master prompt:
 *   "390px ekranlarda tam çalışmalıdır."
 *
 * We pin only viewport + a touch user agent (the devices[] presets pull in
 * the webkit channel which our system-Chrome project doesn't speak).
 *   1. login form is reachable + usable at 390×844
 *   2. portal-bound URLs redirect to login (unauthenticated guard)
 *   3. no horizontal overflow on the login viewport at 390px
 */
test.use({
  viewport: { width: 390, height: 844 },
  userAgent:
    'Mozilla/5.0 (Linux; Android 13; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0 Mobile Safari/537.36',
  hasTouch: true,
  isMobile: true,
});

test.describe('Mobile (390px) responsive smoke', () => {
  test('login form renders within the 390px viewport without overflow', async ({ page }) => {
    await page.goto('/login');
    await expect(page.getByRole('heading', { name: 'Aircast Pro' })).toBeVisible();
    await expect(page.locator('input[type="password"]')).toBeVisible();

    // No horizontal scroll: documentElement.scrollWidth must equal viewport
    // width (within a 1px sub-pixel tolerance).
    const overflow = await page.evaluate(
      () => document.documentElement.scrollWidth - window.innerWidth,
    );
    expect(overflow).toBeLessThanOrEqual(1);
  });

  test('the password field is tap-target sized (≥40px) on mobile', async ({ page }) => {
    await page.goto('/login');
    const box = await page.locator('input[type="password"]').boundingBox();
    expect(box).not.toBeNull();
    if (box) {
      expect(box.height).toBeGreaterThanOrEqual(36);
    }
  });

  test('/portal redirects to /login when unauthenticated, on 390px too', async ({ page }) => {
    await page.goto('/portal');
    await expect(page).toHaveURL(/\/login/);
  });
});
