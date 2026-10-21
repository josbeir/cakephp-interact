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
      { text: 'Documentation', link: '/why' },
      { text: 'Examples', link: '/examples' },
    ],
    sidebar: {
      '/examples': [
        {
          text: 'Examples',
          items: [
            { text: 'Overview and demo', link: '/examples' },
            { text: 'Article form', link: '/examples/article-form' },
            { text: 'Save and redirect', link: '/examples/redirect' },
            { text: 'Invoice download', link: '/examples/download' },
            { text: 'Search and pagination', link: '/examples/search' },
            { text: 'Dependent fields', link: '/examples/dependent-fields' },
            { text: 'Single document upload', link: '/examples/upload' },
            { text: 'Job polling', link: '/examples/polling' },
            { text: 'Enhanced select', link: '/examples/enhanced-select' },
            { text: 'Chart updates', link: '/examples/chart' },
          ],
        },
      ],
      '/': [
        {
          text: 'Getting started',
          items: [
            { text: 'Overview', link: '/why' },
            { text: 'Installation', link: '/installation' },
            { text: 'Quickstart', link: '/getting-started' },
          ],
        },
        {
          text: 'Essentials',
          items: [
            { text: 'Islands', link: '/guide/islands' },
            { text: 'Properties', link: '/guide/state' },
            { text: 'Actions', link: '/guide/actions' },
            { text: 'Lifecycle', link: '/guide/lifecycle' },
            { text: 'Nested islands', link: '/guide/nesting' },
            { text: 'Events', link: '/guide/events' },
            { text: 'Page islands', link: '/guide/page-islands' },
          ],
        },
        {
          text: 'Features',
          items: [
            { text: 'URL state', link: '/guide/url-state' },
            { text: 'Redirects', link: '/guide/redirects' },
            { text: 'Downloads', link: '/guide/downloads' },
            { text: 'Validation', link: '/guide/forms' },
            { text: 'File uploads', link: '/guide/uploads' },
            { text: 'Error handling', link: '/guide/failures' },
          ],
        },
        {
          text: 'HTML directives',
          link: '/directives/',
          items: [
            { text: 'x-interact:model', link: '/directives/model' },
            { text: 'x-interact:submit', link: '/directives/submit' },
            { text: 'x-interact:poll', link: '/directives/poll' },
            { text: 'x-interact:ignore', link: '/directives/ignore' },
          ],
        },
        {
          text: 'JavaScript',
          items: [
            { text: 'Alpine', link: '/guide/alpine' },
            { text: '$server', link: '/reference/server' },
          ],
        },
        {
          text: 'Advanced',
          collapsed: true,
          items: [
            { text: 'Rendering and helpers', link: '/reference/rendering' },
            { text: 'Requests and DOM updates', link: '/reference/browser' },
            { text: 'Security and deployment', link: '/security' },
            { text: 'Troubleshooting', link: '/troubleshooting' },
          ],
        },
      ],
    },
    search: { provider: 'local' },
    outline: { level: [2, 3] },
    editLink: { pattern: `${repository}/edit/main/docs/:path` },
    socialLinks: [{ icon: 'github', link: repository }],
    footer: {
      message: `Released under the MIT License. <a href="${base}contributing.html">Contributing</a>`,
    },
  },
});
