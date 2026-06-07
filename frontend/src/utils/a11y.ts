/**
 * Faz H5-4 — Erişilebilirlik (a11y) yardımcı modülü.
 *
 * Üç temel iyileştirme:
 *   1. Skip-to-content: klavye kullanıcıları header navigation'ı atlayıp
 *      ana içeriğe gidebilsin (Tab → Enter → main odaklanır).
 *   2. Route announcement: SPA navigation'da document.title değişirse
 *      screen reader yeni sayfayı anons eder (WCAG 2.4.2).
 *   3. Focus management: yeni sayfaya geçince main element'e focus ver,
 *      tab sırası başa sarılsın.
 *
 * Kullanım:
 *   import { installA11y } from '#/utils/a11y';
 *   installA11y(router);    // main.ts içinde createApp sonrası.
 */
import type { Router } from 'vue-router';

const DEFAULT_TITLE = 'AdCast Pro · Radyo Yayın Platformu';

interface InstallA11yOptions {
  /** Sayfa title prefix (default 'AdCast Pro'). */
  titleBase?: string;
  /** Main element id. App.vue'da `<main :id="...">` ile eşleşmeli. */
  mainElementId?: string;
}

export function installA11y(router: Router, options: InstallA11yOptions = {}): void {
  const base = options.titleBase ?? DEFAULT_TITLE;
  const mainId = options.mainElementId ?? 'main-content';

  router.afterEach((to) => {
    // Title formatı: "Sayfa · AdCast Pro" — screen reader title değişimini okur.
    const meta = (to.meta ?? {}) as { title?: string };
    const pageTitle = typeof meta.title === 'string' && meta.title.length > 0
      ? `${meta.title} · AdCast Pro`
      : base;
    if (typeof document !== 'undefined') {
      document.title = pageTitle;

      // Focus main — WCAG 2.4.3 (Focus Order).
      // tabindex=-1 → programmatic focus mümkün, tab sırasında yer almaz.
      // RAF ile DOM çizimi bitsin.
      requestAnimationFrame(() => {
        const el = document.querySelector<HTMLElement>(`#${mainId}`);
        if (el) {
          if (!el.hasAttribute('tabindex')) {
            el.setAttribute('tabindex', '-1');
          }
          el.focus({ preventScroll: false });
        }
      });
    }
  });
}

/**
 * Skip-link click handler — anchor default davranışı SPA'da bazı tarayıcılarda
 * scroll yapmayabilir; manuel olarak main'e focus + scroll garantileyelim.
 */
export function focusMainContent(mainElementId = 'main-content'): void {
  if (typeof document === 'undefined') return;
  const el = document.querySelector<HTMLElement>(`#${mainElementId}`);
  if (!el) return;
  if (!el.hasAttribute('tabindex')) {
    el.setAttribute('tabindex', '-1');
  }
  el.focus({ preventScroll: false });
  el.scrollIntoView({ behavior: 'auto', block: 'start' });
}
