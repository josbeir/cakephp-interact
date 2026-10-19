import { readFile, rm, writeFile } from 'node:fs/promises';
import { build } from 'esbuild';
await rm('build/js', { recursive: true, force: true });
await build({
  entryPoints: ['resources/ts/standalone.ts'],
  bundle: true,
  minify: true,
  format: 'iife',
  target: 'es2022',
  outfile: 'webroot/js/runtime.js',
  legalComments: 'eof',
});
await build({
  entryPoints: ['resources/ts/index.ts'],
  bundle: true,
  format: 'esm',
  target: 'es2022',
  outfile: 'build/js/index.js',
  external: ['alpinejs', '@alpinejs/morph'],
});

const notices = await Promise.all([
  readFile('resources/licenses/alpine.txt', 'utf8'),
  readFile('node_modules/@vue/reactivity/LICENSE', 'utf8'),
  readFile('node_modules/@vue/shared/LICENSE', 'utf8'),
]);
await writeFile('webroot/js/THIRD-PARTY-LICENSES.txt', notices.join('\n\n'));
