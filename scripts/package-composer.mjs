import { existsSync, mkdirSync, readFileSync, readdirSync } from 'node:fs';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
const execute = promisify(execFile);

const { version } = JSON.parse(readFileSync('package.json', 'utf8'));
if (!/^\d+\.\d+\.\d+(?:-[a-zA-Z0-9.-]+)?$/.test(version)) {
  throw new Error('A valid release version is required.');
}
for (const path of [
  'webroot/js/runtime.js',
  'webroot/js/THIRD-PARTY-LICENSES.txt',
]) {
  if (!existsSync(path))
    throw new Error(`Build the browser assets first: ${path}`);
}
mkdirSync('build/releases', { recursive: true });
const archive = `build/releases/cakephp-interact-${version}.tar.gz`;
// Stable metadata makes repeated builds of identical sources byte-for-byte equal.
await execute('tar', [
  '--sort=name',
  '--mtime=@0',
  '--owner=0',
  '--group=0',
  '--numeric-owner',
  '-czf',
  archive,
  'composer.json',
  'LICENSE',
  'README.md',
  'AGENTS.md',
  ...readdirSync('docs', { recursive: true })
    .filter(
      (path) =>
        path.endsWith('.md') &&
        !path.startsWith('.vitepress/') &&
        !path.startsWith('public/'),
    )
    .sort()
    .map((path) => `docs/${path}`),
  'docs/public',
  'src',
  'webroot',
]);
const { stdout: entries } = await execute('tar', ['-tzf', archive], {
  encoding: 'utf8',
});
if (
  !entries.includes('webroot/js/runtime.js\n') ||
  !entries.includes('docs/public/branding/interact-mark.svg\n') ||
  !entries.includes('docs/public/branding/interact-mark.png\n') ||
  /(?:^|\/)(?:vendor|node_modules|tests|\.git)\//m.test(entries)
) {
  throw new Error(
    'The Composer archive is missing assets or contains development files.',
  );
}
console.log(`Created ${archive}`);
