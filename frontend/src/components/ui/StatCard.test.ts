import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

import StatCard from './StatCard.vue';

describe('StatCard', () => {
  it('renders label and value', () => {
    const wrapper = mount(StatCard, { props: { label: 'Aktif Radyo', value: 7 } });
    expect(wrapper.find('.stat__label').text()).toBe('Aktif Radyo');
    expect(wrapper.find('.stat__value').text()).toBe('7');
  });

  it('renders a hint when provided', () => {
    const wrapper = mount(StatCard, {
      props: { label: 'X', value: 1, hint: '4 bölgede' },
    });
    expect(wrapper.find('.stat__hint').exists()).toBe(true);
    expect(wrapper.find('.stat__hint').text()).toBe('4 bölgede');
  });

  it('omits the hint element when no hint is given', () => {
    const wrapper = mount(StatCard, { props: { label: 'X', value: 1 } });
    expect(wrapper.find('.stat__hint').exists()).toBe(false);
  });

  it('applies the tone class', () => {
    const wrapper = mount(StatCard, {
      props: { label: 'X', value: 1, tone: 'bad' },
    });
    expect(wrapper.find('.stat').classes()).toContain('tone-bad');
  });

  it('defaults to the "default" tone', () => {
    const wrapper = mount(StatCard, { props: { label: 'X', value: 1 } });
    expect(wrapper.find('.stat').classes()).toContain('tone-default');
  });

  it('renders an svg icon path for a known icon key', () => {
    const wrapper = mount(StatCard, {
      props: { label: 'X', value: 1, icon: 'radio' },
    });
    expect(wrapper.find('.stat__icon path').exists()).toBe(true);
  });

  it('renders no icon for an unknown icon key', () => {
    const wrapper = mount(StatCard, {
      props: { label: 'X', value: 1, icon: 'does-not-exist' },
    });
    expect(wrapper.find('.stat__icon').exists()).toBe(false);
  });
});
