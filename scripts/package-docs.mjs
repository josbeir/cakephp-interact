import { cp, mkdir, readdir, rm, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { URL, pathToFileURL } from 'node:url';
import { JSDOM } from 'jsdom';
import {
  originRedirect,
  pagesOutput,
  publicUrl,
} from '../docs/.vitepress/publication.ts';

const source = 'docs/.vitepress/dist';
// Expose the exact index VitePress built, without rebuilding documents or ranking data.
const chunks = path.join(source, 'assets/chunks');
const indexes = (await readdir(chunks)).filter((file) =>
  /^@localSearchIndexroot\.[\w-]+\.js$/.test(file),
);
if (indexes.length !== 1)
  throw new Error('Expected one VitePress root search index.');
const { default: serializedIndex } = await import(
  pathToFileURL(path.resolve(chunks, indexes[0])).href
);
if (typeof serializedIndex !== 'string')
  throw new Error('VitePress search index must export serialized JSON.');
const index = JSON.parse(serializedIndex);
if (index.serializationVersion !== 2 || !(index.documentCount > 0))
  throw new Error('Unsupported or empty VitePress search index.');
await writeFile(path.join(source, 'search-index.json'), serializedIndex);

await rm(pagesOutput, { recursive: true, force: true });
await mkdir(pagesOutput, { recursive: true });
await cp(source, path.join(pagesOutput, '_docs'), { recursive: true });
await writeFile(path.join(pagesOutput, '.nojekyll'), '');

/** Keep old page URLs working without exposing a second documentation site. */
async function redirects(directory = '') {
  for (const entry of await readdir(path.join(source, directory), {
    withFileTypes: true,
  })) {
    const relative = path.join(directory, entry.name);
    if (entry.isDirectory()) await redirects(relative);
    else if (entry.name.endsWith('.html')) {
      const destination = path.join(pagesOutput, relative);
      await mkdir(path.dirname(destination), { recursive: true });
      if (relative === '404.html') {
        // The proxy must receive a real docs 404, not a redirect back to itself.
        await cp(path.join(source, relative), destination);
        continue;
      }
      const target = new URL(
        relative.replace(/(^|\/)index\.html$/, '$1'),
        publicUrl,
      ).href;
      const document = new JSDOM(
        '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Documentation moved</title><meta name="robots" content="noindex"><link rel="canonical"><script></script><meta http-equiv="refresh"></head><body><main><h1>Documentation moved</h1><p>Read the <a>documentation on cakeinteract.dev</a>.</p></main></body></html>',
      ).window.document;
      document.querySelector('link').setAttribute('href', target);
      document.querySelector('script').textContent = originRedirect;
      document
        .querySelector('meta[http-equiv]')
        .setAttribute('content', `0; url=${target}`);
      document.querySelector('a').setAttribute('href', target);
      await writeFile(
        destination,
        `<!doctype html>\n${document.documentElement.outerHTML}\n`,
      );
    }
  }
}
await redirects();

console.log(`Documentation Pages artifact prepared in ${pagesOutput}.`);
