import { defineConfig } from 'vitepress';

const repository = 'https://github.com/josbeir/cakephp-interact';
const base = '/cakephp-interact/';
const hostname = `https://josbeir.github.io${base}`;
const socialImage = new URL('branding/interact-mark.png', hostname).href;

export default defineConfig({
  title: 'CakePHP Interact',
  titleTemplate: ':title | CakePHP Interact',
  description:
    'Build interactive CakePHP pages with PHP islands and native templates.',
  lang: 'en-US',
  base,
  srcExclude: ['public/**'],
  head: [
    [
      'link',
      {
        rel: 'icon',
        type: 'image/svg+xml',
        href: `${base}branding/interact-mark.svg`,
      },
    ],
    ['meta', { property: 'og:type', content: 'website' }],
    ['meta', { property: 'og:site_name', content: 'CakePHP Interact' }],
    ['meta', { property: 'og:locale', content: 'en_US' }],
    ['meta', { property: 'og:image', content: socialImage }],
    ['meta', { property: 'og:image:type', content: 'image/png' }],
    ['meta', { property: 'og:image:width', content: '512' }],
    ['meta', { property: 'og:image:height', content: '512' }],
    ['meta', { property: 'og:image:alt', content: 'CakePHP Interact logo' }],
    ['meta', { name: 'twitter:card', content: 'summary' }],
    ['meta', { name: 'twitter:image', content: socialImage }],
    ['meta', { name: 'twitter:image:alt', content: 'CakePHP Interact logo' }],
  ],
  sitemap: { hostname },
  transformPageData(pageData) {
    const path = pageData.relativePath
      .replace(/(^|\/)index\.md$/, '$1')
      .replace(/\.md$/, '.html');
    const url = new URL(path, hostname).href;
    const title =
      pageData.titleTemplate === false
        ? pageData.title
        : `${pageData.title} | CakePHP Interact`;

    pageData.frontmatter.head ??= [];
    pageData.frontmatter.head.push(
      ['link', { rel: 'canonical', href: url }],
      ['meta', { property: 'og:title', content: title }],
      ['meta', { property: 'og:description', content: pageData.description }],
      ['meta', { property: 'og:url', content: url }],
      ['meta', { name: 'twitter:title', content: title }],
      ['meta', { name: 'twitter:description', content: pageData.description }],
    );
  },
  transformHead({ pageData }) {
    if (pageData.isNotFound) {
      return [['meta', { name: 'robots', content: 'noindex' }]];
    }
  },
  lastUpdated: true,
  themeConfig: {
    logo: {
      src: '/branding/interact-mark.svg',
      alt: '',
      width: 24,
      height: 24,
    },
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
          { text: 'Alpine and $server', link: '/guide/alpine' },
          { text: 'Actions and feedback', link: '/guide/actions' },
          { text: 'Search and pagination', link: '/guide/search' },
          { text: 'Polling', link: '/guide/polling' },
          { text: 'File uploads', link: '/guide/uploads' },
          { text: 'JavaScript widgets', link: '/guide/javascript-widgets' },
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
