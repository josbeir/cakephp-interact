import { readFile, readdir } from 'node:fs/promises';
import path from 'node:path';
import { URL } from 'node:url';
import { JSDOM } from 'jsdom';

const base = '/cakephp-interact/';
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
  await readFile('tests/docs/published-anchors.json', 'utf8'),
);
for (const [source, anchors] of Object.entries(published)) {
  const document = pages.get(source.replace(/\.md$/, '.html'));
  for (const id of anchors)
    if (!document?.getElementById(id))
      failures.push(`${source}: missing published anchor #${id}`);
}
for (const [file, document] of pages) {
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
    const url = new URL(
      link.getAttribute('href'),
      `https://docs.invalid${base}${file}`,
    );
    if (url.origin !== 'https://docs.invalid' || !url.pathname.startsWith(base))
      continue;
    let target = decodeURIComponent(url.pathname.slice(base.length));
    if (!path.extname(target))
      target += target.endsWith('/') ? 'index.html' : '.html';
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
if (failures.length)
  throw new Error(`Documentation checks failed:\n${failures.join('\n')}`);
console.log(
  `Documentation checks passed: ${pages.size} pages, internal links, published anchors, and HTML-first tabs.`,
);
