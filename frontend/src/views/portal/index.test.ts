import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock('#/api/modules/auth', () => ({
  logout: vi.fn().mockResolvedValue(undefined),
}));

vi.mock('#/api/modules/support', async (importOriginal) => {
  const actual = await importOriginal<typeof import('#/api/modules/support')>();
  return {
    ...actual,
    listSupportTickets: vi.fn().mockResolvedValue({ result: { tickets: [] } }),
    createSupportTicket: vi.fn().mockResolvedValue({ result: { id: 't1' } }),
    getSupportTicket: vi.fn().mockResolvedValue({ result: { ticket: {}, messages: [] } }),
    replySupportTicket: vi.fn().mockResolvedValue({ result: { id: 'm1' } }),
  };
});

vi.mock('#/api/modules/portal', async (importOriginal) => {
  const actual = await importOriginal<typeof import('#/api/modules/portal')>();
  return {
    ...actual,
    getPortalMe: vi.fn().mockResolvedValue({
      result: {
        station_id: 's1',
        name: 'Mesk FM',
        slug: 'mesk-fm',
        logo_url: null,
        frequency: '95.5',
        company_name: 'Mesk Medya A.Ş.',
        contact_name: 'Yetkili',
        phone: '0312 000 0000',
        email: 'info@mesk.fm',
        website: 'https://mesk.fm',
        region_code: 'ic-anadolu',
        region_name: 'İç Anadolu',
        city_name: 'Konya',
        status: 'active',
        is_active: true,
        last_login_at: '2026-06-06T08:00:00Z',
        last_broadcast_at: '2026-06-06T07:30:00Z',
      },
    }),
    getPortalLinks: vi.fn().mockResolvedValue({
      result: {
        links: [
          { purpose: 'news', token: 'aa', urls: { json: 'u1', xml: 'u2', m3u: 'u3', pls: 'u4' } },
          { purpose: 'sports', token: 'bb', urls: { json: 'u1', xml: 'u2', m3u: 'u3', pls: 'u4' } },
          { purpose: 'economy', token: 'cc', urls: { json: 'u1', xml: 'u2', m3u: 'u3', pls: 'u4' } },
          { purpose: 'weather', token: 'dd', urls: { json: 'u1', xml: 'u2', m3u: 'u3', pls: 'u4' } },
          { purpose: 'sponsor', token: 'ee', urls: { json: 'u1', xml: 'u2', m3u: 'u3', pls: 'u4' } },
          { purpose: 'ad', token: 'ff', urls: { json: 'u1', xml: 'u2', m3u: 'u3', pls: 'u4' } },
          { purpose: 'special', token: 'gg', urls: { json: 'u1', xml: 'u2', m3u: 'u3', pls: 'u4' } },
          { purpose: 'emergency', token: 'hh', urls: { json: 'u1', xml: 'u2', m3u: 'u3', pls: 'u4' } },
        ],
      },
    }),
    getPortalFeeds: vi.fn().mockResolvedValue({ result: { plans: [] } }),
    getPortalMedia: vi.fn().mockResolvedValue({ result: { items: [] } }),
    getPortalActivity: vi.fn().mockResolvedValue({ result: { logs: [] } }),
  };
});

import Portal from './index.vue';

describe('Partner Radio Portal view', () => {
  afterEach(() => vi.restoreAllMocks());

  it('renders the corporate card from /portal/me', async () => {
    const wrapper = mount(Portal);
    await flushPromises();
    expect(wrapper.text()).toContain('Mesk FM');
    expect(wrapper.text()).toContain('95.5');
    expect(wrapper.text()).toContain('Konya');
    expect(wrapper.text()).toContain('Mesk Medya A.Ş.');
    wrapper.unmount();
  });

  it('renders all 8 purpose-keyed link cards on the Linkler tab', async () => {
    const wrapper = mount(Portal);
    await flushPromises();
    expect(wrapper.findAll('.prt__link')).toHaveLength(8);
    expect(wrapper.text()).toContain('Haber');
    expect(wrapper.text()).toContain('Spor');
    expect(wrapper.text()).toContain('Acil Yayın');
    wrapper.unmount();
  });

  it('switches to the Aktivite tab', async () => {
    const wrapper = mount(Portal);
    await flushPromises();
    const activityTab = wrapper.findAll('.prt__tab').find((t) => t.text().includes('Aktivite'));
    await activityTab!.trigger('click');
    expect(wrapper.find('.prt__activity').exists() || wrapper.text().includes('aktivite yok')).toBe(true);
    wrapper.unmount();
  });
});
