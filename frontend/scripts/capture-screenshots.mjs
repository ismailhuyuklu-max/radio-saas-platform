/**
 * Documentation screenshot pass.
 *
 * Drives the Vite dev server on :3000 with two pre-minted session cookies
 * (admin + partner radio) and captures every UI route at three viewports
 * — desktop 1440×900, tablet 820×1180, mobile 390×844.
 *
 * Output: docs/screenshots/<role>/<route>/<viewport>.png
 *
 * Usage (from frontend/):
 *   node scripts/capture-screenshots.mjs
 *
 * Prereqs:
 *   - Vite dev server running on http://localhost:3000
 *   - Backend reachable via the Vite proxy
 *   - docs/doc-sessions.json populated (run bin/mint-doc-sessions.php)
 */
import { chromium } from 'playwright';
import { promises as fs } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const ROOT = path.resolve(__dirname, '..', '..');
const SESSIONS_FILE = path.join(ROOT, 'docs', 'doc-sessions.json');
const OUT_DIR = path.join(ROOT, 'docs', 'screenshots');

const BASE_URL = process.env.DOC_BASE_URL || 'http://localhost:3000';

const VIEWPORTS = [
  { name: 'desktop', width: 1440, height: 900 },
  { name: 'tablet', width: 820, height: 1180 },
  { name: 'mobile', width: 390, height: 844 },
];

const ADMIN_ROUTES = [
  { path: '/login', name: '01-login', title: 'Giriş Ekranı' },
  { path: '/radio-platform/operations', name: '02-operations', title: 'Yayın Merkezi' },
  { path: '/radio-platform/dashboard', name: '03-dashboard', title: 'Genel Bakış' },
  { path: '/radio-platform/matrix', name: '04-matrix', title: 'Bölgesel Durum Matrisi' },
  { path: '/radio-platform/stations', name: '05-stations', title: 'İstasyon Yönetimi' },
  { path: '/radio-platform/sponsors', name: '06-sponsors', title: 'Sponsor Yönetimi' },
  { path: '/radio-platform/traffic-center', name: '07-traffic-center', title: 'Yayın Trafik Merkezi' },
  { path: '/radio-platform/timeline', name: '08-timeline', title: 'Zaman Çizelgesi' },
  { path: '/radio-platform/kanban', name: '09-kanban', title: 'Haber Akışı (Kanban)' },
  { path: '/radio-platform/planning', name: '10-planning', title: 'Planlama' },
  { path: '/radio-platform/ad-traffic', name: '11-ad-traffic', title: 'Reklam Trafik' },
  { path: '/radio-platform/reports', name: '12-reports', title: 'Raporlar' },
  { path: '/radio-platform/media-library', name: '13-media-library', title: 'Medya Kütüphanesi' },
  { path: '/radio-platform/noc', name: '14-noc', title: 'Sistem İzleme (NOC)' },
  { path: '/radio-platform/security', name: '15-security', title: 'Güvenlik' },
  { path: '/radio-platform/access', name: '16-access', title: 'Erişim Yönetimi' },
];

const PARTNER_ROUTES = [
  { path: '/portal', name: '20-portal-links', title: 'Partner Portal — Linkler', wait: 600 },
  { path: '/portal', name: '21-portal-feeds', title: 'Partner Portal — Bugünkü Yayınlar', tab: 'Bugünkü Yayınlar', wait: 700 },
  { path: '/portal', name: '22-portal-media', title: 'Partner Portal — İndirme Merkezi', tab: 'İndirme Merkezi', wait: 700 },
  { path: '/portal', name: '23-portal-activity', title: 'Partner Portal — Aktivite', tab: 'Aktivite', wait: 700 },
  { path: '/portal', name: '24-portal-support', title: 'Partner Portal — Destek', tab: 'Destek', wait: 700 },
  { path: '/portal', name: '25-portal-apikeys', title: 'Partner Portal — API Anahtarları', tab: 'API Anahtarları', wait: 700 },
  { path: '/portal', name: '26-portal-security', title: 'Partner Portal — Güvenlik (MFA)', tab: 'Güvenlik', wait: 700 },
];

async function ensureDir(dir) {
  await fs.mkdir(dir, { recursive: true });
}

async function capture(page, route, viewport, role) {
  const targetUrl = BASE_URL + route.path;
  try {
    await page.setViewportSize({ width: viewport.width, height: viewport.height });
    await page.goto(targetUrl, { waitUntil: 'networkidle', timeout: 20_000 }).catch(() => null);
    // Some routes are SPA-only: give Vue a tick to mount + dispatch fetches.
    await page.waitForTimeout(900);

    // If a specific tab was requested, click it once present.
    if (route.tab) {
      try {
        const tab = page.getByRole('button', { name: new RegExp(route.tab, 'i') }).first();
        await tab.click({ timeout: 3000 });
        await page.waitForTimeout(route.wait ?? 500);
      } catch {
        /* tab not found — capture default state */
      }
    } else if (route.wait) {
      await page.waitForTimeout(route.wait);
    }

    const outDir = path.join(OUT_DIR, role, route.name);
    await ensureDir(outDir);
    const file = path.join(outDir, `${viewport.name}.png`);
    await page.screenshot({ path: file, fullPage: viewport.name === 'desktop' });
    console.log(`  ${role}/${route.name}/${viewport.name}.png`);
    return true;
  } catch (err) {
    console.warn(`  FAIL ${role}/${route.name}/${viewport.name}: ${err.message}`);
    return false;
  }
}

async function main() {
  const sessions = JSON.parse(await fs.readFile(SESSIONS_FILE, 'utf-8'));
  await ensureDir(OUT_DIR);

  const browser = await chromium.launch({ channel: 'chrome' });
  let captured = 0;
  let failed = 0;

  // --- Admin pass ---
  const adminCtx = await browser.newContext({ ignoreHTTPSErrors: true });
  await adminCtx.addCookies([
    {
      name: 'radio_session',
      value: sessions.admin_token,
      domain: 'localhost',
      path: '/',
      httpOnly: true,
      sameSite: 'Lax',
    },
  ]);
  const adminPage = await adminCtx.newPage();
  // Seed localStorage with a user record so router doesn't bounce to /login.
  await adminPage.goto(BASE_URL);
  await adminPage.evaluate((uid) => {
    localStorage.setItem(
      'userInfo',
      JSON.stringify({ userId: uid, username: 'admin', realName: 'AdCast Pro Admin', roles: ['super'] }),
    );
  }, sessions.admin_user_id);

  console.log('=== Admin capture ===');
  for (const route of ADMIN_ROUTES) {
    for (const vp of VIEWPORTS) {
      const ok = await capture(adminPage, route, vp, 'admin');
      ok ? captured++ : failed++;
    }
  }
  await adminCtx.close();

  // --- Partner pass ---
  const partnerCtx = await browser.newContext({ ignoreHTTPSErrors: true });
  await partnerCtx.addCookies([
    {
      name: 'radio_session',
      value: sessions.partner_token,
      domain: 'localhost',
      path: '/',
      httpOnly: true,
      sameSite: 'Lax',
    },
  ]);
  const partnerPage = await partnerCtx.newPage();
  await partnerPage.goto(BASE_URL);
  await partnerPage.evaluate((data) => {
    localStorage.setItem(
      'userInfo',
      JSON.stringify({
        userId: data.uid,
        username: data.username,
        realName: 'Demo Yetkili',
        roles: ['station_user'],
      }),
    );
  }, { uid: sessions.partner_user_id, username: sessions.partner_username });

  console.log('=== Partner capture ===');
  for (const route of PARTNER_ROUTES) {
    for (const vp of VIEWPORTS) {
      const ok = await capture(partnerPage, route, vp, 'partner');
      ok ? captured++ : failed++;
    }
  }
  await partnerCtx.close();

  await browser.close();
  console.log(`\nDone. captured=${captured} failed=${failed}`);
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
