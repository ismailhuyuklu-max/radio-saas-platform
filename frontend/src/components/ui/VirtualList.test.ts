import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

import VirtualList from './VirtualList.vue';

function makeItems(n: number) {
  return Array.from({ length: n }, (_, i) => ({ id: `id-${i}`, label: `Row ${i}` }));
}

describe('VirtualList', () => {
  it('renders only a window of rows, not all items', () => {
    const items = makeItems(1000);
    const wrapper = mount(VirtualList, {
      props: { items, rowHeight: 50, height: 500, overscan: 4 },
      slots: { default: '<span class="cell">x</span>' },
    });
    // 500/50 = 10 visible + 2*4 overscan = ~18 rows, far fewer than 1000.
    const rows = wrapper.findAll('.vlist__row');
    expect(rows.length).toBeGreaterThan(0);
    expect(rows.length).toBeLessThan(40);
  });

  it('sizes the spacer to the full virtual height', () => {
    const items = makeItems(200);
    const wrapper = mount(VirtualList, {
      props: { items, rowHeight: 40, height: 400 },
      slots: { default: '<span>x</span>' },
    });
    const spacer = wrapper.find('.vlist__spacer');
    expect(spacer.attributes('style')).toContain('8000px'); // 200 * 40
  });

  it('exposes the item to the default slot', () => {
    const items = makeItems(5);
    const wrapper = mount(VirtualList, {
      props: { items, rowHeight: 40, height: 400 },
      slots: { default: '<template #default="{ item }"><span class="c">{{ item.label }}</span></template>' },
    });
    expect(wrapper.find('.c').text()).toBe('Row 0');
  });

  it('renders an empty window for an empty list without throwing', () => {
    const wrapper = mount(VirtualList, {
      props: { items: [], rowHeight: 40, height: 400 },
      slots: { default: '<span>x</span>' },
    });
    expect(wrapper.findAll('.vlist__row').length).toBe(0);
    expect(wrapper.find('.vlist__spacer').attributes('style')).toContain('0px');
  });
});
