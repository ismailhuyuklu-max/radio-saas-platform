import type { Preview } from '@storybook/vue3-vite';

import '../src/design/main.less';

const preview: Preview = {
  parameters: {
    layout: 'centered',
    backgrounds: {
      default: 'adcast-dark',
      values: [{ name: 'adcast-dark', value: '#090d16' }],
    },
    controls: { matchers: { color: /(background|color)$/i, date: /Date$/i } },
  },
};

export default preview;
