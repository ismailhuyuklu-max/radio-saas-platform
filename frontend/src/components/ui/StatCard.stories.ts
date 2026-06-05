import type { Meta, StoryObj } from '@storybook/vue3-vite';

import StatCard from './StatCard.vue';

const meta: Meta<typeof StatCard> = {
  title: 'UI/StatCard',
  component: StatCard,
  tags: ['autodocs'],
  argTypes: {
    tone: {
      control: 'select',
      options: ['default', 'ok', 'warn', 'bad', 'info', 'brand'],
    },
    icon: {
      control: 'select',
      options: ['', 'radio', 'news', 'megaphone', 'map', 'pulse', 'clock'],
    },
    value: { control: 'text' },
  },
  decorators: [
    () => ({ template: '<div style="width:240px"><story /></div>' }),
  ],
};

export default meta;
type Story = StoryObj<typeof StatCard>;

export const Default: Story = {
  args: { label: 'Aktif Radyo', value: 7, hint: '12 istasyon', tone: 'info', icon: 'radio' },
};

export const Success: Story = {
  args: { label: 'Canlı Yayınlar', value: 4, hint: 'şu an yayında', tone: 'ok', icon: 'pulse' },
};

export const Warning: Story = {
  args: { label: 'Bekleyen İçerik', value: 3, hint: 'taslak plan', tone: 'warn', icon: 'clock' },
};

export const Danger: Story = {
  args: { label: 'Kaçırılan Yayın', value: 5, hint: 'geçmiş slot', tone: 'bad', icon: 'clock' },
};

export const Brand: Story = {
  args: { label: 'Yayında Reklam', value: 9, hint: 'aktif sponsor', tone: 'brand', icon: 'megaphone' },
};

export const NoIcon: Story = {
  args: { label: 'Toplam Bölge', value: 7, hint: 'yayın bölgesi', tone: 'default' },
};
