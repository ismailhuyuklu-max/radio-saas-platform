/**
 * Faz H5-4 — a11y utility tests.
 *
 * focusMainContent + installA11y route hook'unun çalışması.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createMemoryHistory, createRouter, type Router } from 'vue-router';

import { focusMainContent, installA11y } from './a11y';

function makeRouter(): Router {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'home', component: { template: '<div/>' }, meta: { title: 'Anasayfa' } },
      { path: '/plans', name: 'plans', component: { template: '<div/>' }, meta: { title: 'Planlar' } },
      { path: '/nometa', name: 'nometa', component: { template: '<div/>' } },
    ],
  });
}

describe('focusMainContent', () => {
  beforeEach(() => {
    document.body.innerHTML = '<main id="main-content"></main>';
  });
  afterEach(() => {
    document.body.innerHTML = '';
  });

  it('main element\'e tabindex=-1 set eder + focus alır', () => {
    const main = document.querySelector<HTMLElement>('#main-content')!;
    expect(main.getAttribute('tabindex')).toBeNull();

    focusMainContent();

    expect(main.getAttribute('tabindex')).toBe('-1');
    expect(document.activeElement).toBe(main);
  });

  it('mevcut tabindex korunur', () => {
    const main = document.querySelector<HTMLElement>('#main-content')!;
    main.setAttribute('tabindex', '0');

    focusMainContent();

    expect(main.getAttribute('tabindex')).toBe('0'); // değiştirilmedi
  });

  it('main element yoksa exception fırlatmaz', () => {
    document.body.innerHTML = ''; // main yok
    expect(() => focusMainContent()).not.toThrow();
  });

  it('custom id ile çalışır', () => {
    document.body.innerHTML = '<section id="custom"></section>';
    focusMainContent('custom');
    expect(document.activeElement?.id).toBe('custom');
  });
});

describe('installA11y', () => {
  beforeEach(() => {
    document.body.innerHTML = '<main id="main-content"></main>';
    document.title = '';
  });

  it('route.meta.title varsa "Title · Aircast Pro" yazar', async () => {
    const router = makeRouter();
    installA11y(router);
    await router.push('/plans');
    // afterEach RAF'a sokuyor — title senkron olarak set ediliyor afterEach hook'unda.
    expect(document.title).toBe('Planlar · Aircast Pro');
  });

  it('meta.title yoksa default base kullanır', async () => {
    const router = makeRouter();
    installA11y(router);
    await router.push('/nometa');
    expect(document.title).toContain('Aircast Pro');
  });

  it('custom titleBase override edilebilir', async () => {
    const router = makeRouter();
    installA11y(router, { titleBase: 'CustomBrand' });
    await router.push('/nometa');
    expect(document.title).toBe('CustomBrand');
  });
});
