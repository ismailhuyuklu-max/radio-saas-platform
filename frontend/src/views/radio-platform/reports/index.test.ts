import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock('#/api/modules/radioMedia', async (importOriginal) => {
  const actual = await importOriginal<typeof import('#/api/modules/radioMedia')>();
  return {
    ...actual,
    getProvinceBreakdown: vi.fn().mockResolvedValue({
      type: 'province',
      count: 2,
      rows: [
        { province: 'İstanbul', region_code: 'marmara', region_name: 'Marmara', plan_count: 12, campaign_count: 3 },
        { province: 'İzmir', region_code: 'ege', region_name: 'Ege', plan_count: 4, campaign_count: 1 },
      ],
    }),
    getCustomerBreakdown: vi.fn().mockResolvedValue({
      type: 'customer',
      count: 1,
      rows: [
        { advertiser_name: 'Koç Holding', status: 'active', budget: 100000, planned_spots: 20, aired_spots: 8, impressions: 540000 },
      ],
    }),
  };
});

import Reports from './index.vue';

describe('Report Center view', () => {
  afterEach(() => vi.restoreAllMocks());

  it('renders the 5 report types each with CSV/Excel/PDF buttons', () => {
    const wrapper = mount(Reports);
    expect(wrapper.findAll('.rep__card')).toHaveLength(5);
    // 5 reports x 3 formats
    expect(wrapper.findAll('.rep__btn')).toHaveLength(15);
  });

  it('labels the three export formats', () => {
    const wrapper = mount(Reports);
    const labels = wrapper.findAll('.rep__btn').map((b) => b.text());
    expect(labels).toContain('CSV');
    expect(labels).toContain('Excel');
    expect(labels).toContain('PDF');
  });

  it('loads the province breakdown and renders virtualized rows', async () => {
    const wrapper = mount(Reports);
    await flushPromises();
    // province tab active by default → at least one virtualized row visible
    expect(wrapper.findAll('.rep__row--prov').length).toBeGreaterThan(1);
    expect(wrapper.text()).toContain('İstanbul');
    wrapper.unmount();
  });

  it('switches to the customer breakdown tab', async () => {
    const wrapper = mount(Reports);
    await flushPromises();
    const custTab = wrapper.findAll('.rep__tab').find((t) => t.text().startsWith('Müşteri'));
    await custTab!.trigger('click');
    expect(wrapper.text()).toContain('Koç Holding');
    wrapper.unmount();
  });
});
