import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock('vue-router', () => ({ useRouter: () => ({ push: vi.fn() }) }));

vi.mock('#/api/modules/radioMedia', async (importOriginal) => {
  const actual = await importOriginal<typeof import('#/api/modules/radioMedia')>();
  return {
    ...actual,
    getStations: vi.fn().mockResolvedValue([
      { id: '1', region_code: 'marmara', region_name: 'Marmara', is_active: true, status: 'active', name: 'A' },
      { id: '2', region_code: 'marmara', region_name: 'Marmara', is_active: true, status: 'active', name: 'B' },
      { id: '3', region_code: 'ege', region_name: 'Ege', is_active: true, status: 'active', name: 'C' },
      { id: '4', region_code: 'akdeniz', region_name: 'Akdeniz', is_active: false, status: 'paused', name: 'D' },
    ]),
    getSponsors: vi.fn().mockResolvedValue([]),
    getPlanning: vi.fn().mockResolvedValue({ plans: [], calendar: [], filters: {} }),
  };
});

import Operations from './index.vue';

function cardValue(wrapper: ReturnType<typeof mount>, label: string): string {
  const card = wrapper
    .findAll('.ops__kpis .stat')
    .find((c) => c.find('.stat__label').text() === label);
  return card ? card.find('.stat__value').text() : '';
}

describe('Operations Center view', () => {
  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('renders the 8 operations KPI cards', async () => {
    const wrapper = mount(Operations);
    await flushPromises();
    expect(wrapper.findAll('.ops__kpis .stat')).toHaveLength(8);
    wrapper.unmount();
  });

  it('derives Aktif Radyo / Aktif Bölge from fetched stations', async () => {
    const wrapper = mount(Operations);
    await flushPromises();
    // 3 active stations across 2 regions (marmara, ege)
    expect(cardValue(wrapper, 'Aktif Radyo')).toBe('3');
    expect(cardValue(wrapper, 'Aktif Bölge')).toBe('2');
    wrapper.unmount();
  });

  it('shows a live clock', async () => {
    const wrapper = mount(Operations);
    await flushPromises();
    expect(wrapper.find('.ops__clock').text()).toMatch(/\d{2}:\d{2}:\d{2}/);
    wrapper.unmount();
  });
});
