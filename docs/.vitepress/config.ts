import { defineConfig } from 'vitepress';

const repository = 'https://github.com/josbeir/cakephp-interact';

export default defineConfig({
  title: 'CakePHP Interact',
  titleTemplate: ':title | CakePHP Interact',
  description:
    'Build interactive CakePHP pages with PHP islands and native templates.',
  lang: 'en-US',
  base: '/cakephp-interact/',
  lastUpdated: true,
  sitemap: { hostname: 'https://josbeir.github.io/cakephp-interact/' },
  themeConfig: {
    nav: [
      { text: 'Guide', link: '/getting-started' },
      { text: 'Examples', link: '/examples' },
      { text: 'Reference', link: '/api' },
    ],
    sidebar: [
      {
        text: 'Start here',
        items: [
          { text: 'Why Interact?', link: '/why' },
          { text: 'Installation', link: '/installation' },
          { text: 'Your first island', link: '/getting-started' },
          { text: 'Explore the demo', link: '/examples' },
        ],
      },
      {
        text: 'Build interactive pages',
        items: [
          { text: 'State and input binding', link: '/guide/state' },
          { text: 'Actions and feedback', link: '/guide/actions' },
          { text: 'Search and pagination', link: '/guide/search' },
          { text: 'Forms and validation', link: '/guide/forms' },
          { text: 'Failures and recovery', link: '/guide/failures' },
          { text: 'Nested islands and events', link: '/guide/nesting' },
          { text: 'Whole-page islands', link: '/guide/page-islands' },
        ],
      },
      {
        text: 'Reference',
        items: [
          { text: 'PHP API and lifecycle', link: '/api' },
          { text: 'Browser API', link: '/reference/browser' },
          { text: 'Rendering and services', link: '/reference/rendering' },
        ],
      },
      {
        text: 'Help and project',
        items: [
          { text: 'Security and deployment', link: '/security' },
          { text: 'Troubleshooting', link: '/troubleshooting' },
          { text: 'Contributing', link: '/contributing' },
        ],
      },
    ],
    search: { provider: 'local' },
    outline: { level: [2, 3] },
    editLink: { pattern: `${repository}/edit/main/docs/:path` },
    socialLinks: [{ icon: 'github', link: repository }],
    footer: { message: 'Released under the MIT License.' },
  },
});
