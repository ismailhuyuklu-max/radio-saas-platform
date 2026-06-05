import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

import Reports from './index.vue';

describe('Report Center view', () => {
  it('renders the 3 report types each with CSV/Excel/PDF buttons', () => {
    const wrapper = mount(Reports);
    expect(wrapper.findAll('.rep__card')).toHaveLength(3);
    // 3 reports x 3 formats
    expect(wrapper.findAll('.rep__btn')).toHaveLength(9);
  });

  it('labels the three export formats', () => {
    const wrapper = mount(Reports);
    const labels = wrapper.findAll('.rep__btn').map((b) => b.text());
    expect(labels).toContain('CSV');
    expect(labels).toContain('Excel');
    expect(labels).toContain('PDF');
  });
});
