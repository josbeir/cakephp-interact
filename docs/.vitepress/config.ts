import { defineConfig } from 'vitepress';
export default defineConfig({
  title: 'CakePHP Interact',
  description: 'Reactive PHP islands for CakePHP',
  base: '/cakephp-interact/',
  themeConfig: {
    nav: [
      { text: 'Get started', link: '/getting-started' },
      { text: 'API', link: '/api' },
    ],
    sidebar: [
      {
        text: 'Interact',
        items: [
          { text: 'Get started', link: '/getting-started' },
          { text: 'API and lifecycle', link: '/api' },
          { text: 'Security', link: '/security' },
          { text: 'Contributing', link: '/contributing' },
        ],
      },
    ],
    search: { provider: 'local' },
  },
});
