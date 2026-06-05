import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock('#/api/modules/radioMedia', async (importOriginal) => {
  const actual = await importOriginal<typeof import('#/api/modules/radioMedia')>();
  return {
    ...actual,
    getMediaLibrary: vi.fn().mockResolvedValue({
      content: [
        { id: 'c1', kind: 'content', title: '08:00 Haber Bülteni', part_code: 'news', slot_time: '08:00', region_code: 'marmara', region_name: 'Marmara', render_state: 'rendered', url: '/api/v1/media-stream/content/c1' },
        { id: 'c2', kind: 'content', title: 'Spor Haberleri', part_code: 'sports', region_code: 'ege', region_name: 'Ege', render_state: 'rendered', url: '/api/v1/media-stream/content/c2' },
      ],
      sponsors: [
        { id: 's1', kind: 'sponsor', title: 'Onatça Motor', part_code: 'sports', placement_type: 'intro', region_code: '', region_name: '', is_global: true, url: '/api/v1/media-stream/sponsor/s1' },
      ],
    }),
  };
});

import MediaLibrary from './index.vue';

describe('Media Library view', () => {
  afterEach(() => vi.restoreAllMocks());

  it('lists content + sponsor tracks with a player bar', async () => {
    const wrapper = mount(MediaLibrary);
    await flushPromises();
    expect(wrapper.findAll('.ml__row')).toHaveLength(3); // 2 content + 1 sponsor
    expect(wrapper.find('.ml__player').exists()).toBe(true);
    expect(wrapper.find('audio').exists()).toBe(true);
    wrapper.unmount();
  });

  it('filters by type (Reklamlar shows only the sponsor)', async () => {
    const wrapper = mount(MediaLibrary);
    await flushPromises();
    const sponsorTab = wrapper.findAll('.ml__tab').find((t) => t.text() === 'Reklamlar');
    await sponsorTab!.trigger('click');
    const rows = wrapper.findAll('.ml__row');
    expect(rows).toHaveLength(1);
    expect(rows[0].text()).toContain('Onatça Motor');
    wrapper.unmount();
  });
});
