import { readFile, readdir } from 'node:fs/promises';
import path from 'node:path';
import { URL } from 'node:url';
import { runInNewContext } from 'node:vm';
import { JSDOM } from 'jsdom';
import {
  originBase,
  originBuildBase,
  pagesOutput,
  publicBase as base,
  publicUrl,
} from '../docs/.vitepress/publication.ts';

const output = 'docs/.vitepress/dist';
const pages = new Map();
const failures = [];

/** Load built pages once so link and legacy-anchor checks share the same DOM. */
async function collect(directory) {
  for (const entry of await readdir(directory, { withFileTypes: true })) {
    if (entry.name.startsWith('.') || entry.name === 'public') continue;
    const source = path.join(directory, entry.name);
    if (entry.isDirectory()) await collect(source);
    else if (source.endsWith('.md')) {
      const file = path.relative('docs', source).replace(/\.md$/, '.html');
      const dom = new JSDOM(await readFile(path.join(output, file), 'utf8'));
      pages.set(file, dom.window.document);
    }
  }
}
await collect('docs');
const published = JSON.parse(
  await readFile('tests/Fixture/Documentation/published-anchors.json', 'utf8'),
);
for (const [source, anchors] of Object.entries(published)) {
  const document = pages.get(source.replace(/\.md$/, '.html'));
  for (const id of anchors)
    if (!document?.getElementById(id))
      failures.push(`${source}: missing published anchor #${id}`);
}
for (const [file, document] of pages) {
  const canonical = new URL(file.replace(/(^|\/)index\.html$/, '$1'), publicUrl)
    .href;
  if (
    document.querySelector('link[rel="canonical"]')?.getAttribute('href') !==
    canonical
  )
    failures.push(`${file}: incorrect canonical URL`);
  const legacy = new JSDOM(await readFile(path.join(pagesOutput, file), 'utf8'))
    .window.document;
  if (
    legacy.querySelector('a')?.getAttribute('href') !== canonical ||
    legacy
      .querySelector('meta[http-equiv="refresh"]')
      ?.getAttribute('content') !== `0; url=${canonical}` ||
    legacy.querySelector('meta[name="robots"]')?.getAttribute('content') !==
      'noindex'
  )
    failures.push(`${file}: missing legacy redirect or no-script fallback`);
  const origin = new JSDOM(
    await readFile(path.join(pagesOutput, '_docs', file), 'utf8'),
  ).window.document;
  if (
    origin.querySelector('link[rel="canonical"]')?.getAttribute('href') !==
    canonical
  )
    failures.push(`${file}: missing canonical page in the origin build`);
  const ids = new Set();
  for (const element of document.querySelectorAll('.vp-doc [id]')) {
    if (ids.has(element.id)) failures.push(`${file}: duplicate #${element.id}`);
    ids.add(element.id);
  }
  for (const group of document.querySelectorAll('.vp-code-group')) {
    const labels = [...group.querySelectorAll('.tabs label')].map((label) =>
      label.textContent.trim(),
    );
    if (labels.includes('FormHelper') && labels[0] !== 'HTML')
      failures.push(
        `${file}: FormHelper examples must have HTML as their first tab`,
      );
  }
  for (const link of document.querySelectorAll('.vp-doc a[href]')) {
    const url = new URL(link.getAttribute('href'), new URL(file, publicUrl));
    if (
      url.origin !== new URL(publicUrl).origin ||
      !url.pathname.startsWith(base)
    )
      continue;
    let target = decodeURIComponent(url.pathname.slice(base.length));
    if (!path.extname(target))
      target += !target || target.endsWith('/') ? 'index.html' : '.html';
    if (!target.endsWith('.html')) continue;
    const destination = pages.get(target);
    if (!destination) failures.push(`${file}: missing page ${target}`);
    else if (
      url.hash &&
      !destination.getElementById(decodeURIComponent(url.hash.slice(1)))
    )
      failures.push(`${file}: missing target ${target}${url.hash}`);
  }
}
// Exercise the built script at origin and public URLs, including the Pages 404
// returned through the proxy for unknown documentation routes.
const notFound = new JSDOM(
  await readFile(path.join(pagesOutput, '404.html'), 'utf8'),
).window.document;
const redirect = [...notFound.querySelectorAll('script')].find((script) =>
  script.textContent.includes('location.hostname'),
)?.textContent;
if (!redirect) throw new Error('The Pages 404 is missing its origin redirect.');
for (const [url, expected] of [
  [`https://josbeir.github.io${originBase}`, publicUrl],
  [
    `https://josbeir.github.io${originBase}guide/forms.html?draft=1#models`,
    `${publicUrl}guide/forms.html?draft=1#models`,
  ],
  [
    `https://josbeir.github.io${originBuildBase}index.html#counter`,
    `${publicUrl}#counter`,
  ],
  [
    `https://josbeir.github.io${originBuildBase}missing.html?draft=1#example`,
    `${publicUrl}missing.html?draft=1#example`,
  ],
  [
    `https://josbeir.github.io${originBuildBase}/example.invalid/path`,
    `${publicUrl}/example.invalid/path`,
  ],
  [`${publicUrl}missing.html?draft=1#example`, undefined],
  ['http://localhost:5173/docs/', undefined],
  ['https://josbeir.github.io/other-project/', undefined],
]) {
  let destination;
  const location = new URL(url);
  runInNewContext(redirect, {
    location: {
      hostname: location.hostname,
      pathname: location.pathname,
      search: location.search,
      hash: location.hash,
      replace(value) {
        destination = value;
      },
    },
  });
  if (destination !== expected)
    failures.push(`${url}: incorrect redirect ${destination}`);
}
if (notFound.querySelector('meta[http-equiv="refresh"]'))
  failures.push('The proxied 404 must not refresh back to itself.');
const sitemap = await readFile(
  path.join(pagesOutput, '_docs/sitemap.xml'),
  'utf8',
);
if (sitemap.includes('josbeir.github.io') || !sitemap.includes(publicUrl))
  failures.push('The documentation sitemap must use the public website URL.');
if (failures.length)
  throw new Error(`Documentation checks failed:\n${failures.join('\n')}`);
console.log(
  `Documentation checks passed: ${pages.size} pages, internal links, published anchors, and HTML-first tabs.`,
);
