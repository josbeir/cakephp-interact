import { execFile, spawn } from 'node:child_process';
import { once } from 'node:events';
import { randomBytes, randomUUID } from 'node:crypto';
import { mkdir } from 'node:fs/promises';
import { resolve } from 'node:path';
import { URL, fileURLToPath } from 'node:url';
import { promisify } from 'node:util';

const execute = promisify(execFile);
const root = fileURLToPath(new URL('../', import.meta.url));
const storage = resolve(
  process.env.INTERACT_DEMO_TMP ||
    fileURLToPath(new URL('../build/tmp', import.meta.url)),
);
const port = process.env.INTERACT_DEMO_PORT || '8765';
if (!/^\d+$/.test(port) || Number(port) < 1 || Number(port) > 65535)
  throw new Error('INTERACT_DEMO_PORT must be a TCP port between 1 and 65535.');
const image = 'interact-demo:frankenphp-1.13.3';
await mkdir(storage, { recursive: true });
const build = spawn('docker', ['build', '--tag', image, 'tests/test_app'], {
  cwd: root,
  stdio: 'inherit',
});
const [built] = await once(build, 'exit');
if (built !== 0)
  throw new Error(
    'Cannot build the FrankenPHP demo. Check Docker access and image availability.',
  );

const secret = randomBytes(32).toString('hex');
const audience = 'https://interact.demo/.well-known/mercure';
const name = `interact-demo-${randomUUID()}`;
const environment = {
  MERCURE_URL: 'http://127.0.0.1:8765/.well-known/mercure',
  MERCURE_PUBLIC_URL: '/.well-known/mercure',
  MERCURE_JWT_SECRET: secret,
  MERCURE_JWT_ISSUER: 'https://localhost',
  MERCURE_JWT_AUDIENCE: audience,
  MERCURE_PUBLISHER_JWT_KEY: secret,
  MERCURE_SUBSCRIBER_JWT_KEY: secret,
  MERCURE_TRUSTED_ISSUERS: 'https://localhost',
  MERCURE_RESOURCE_IDENTIFIER: audience,
  XDG_CONFIG_HOME: '/tmp/interact-caddy/config',
  XDG_DATA_HOME: '/tmp/interact-caddy/data',
};
const preview = spawn(
  'docker',
  [
    'run',
    '--rm',
    '--name',
    name,
    '--publish',
    `127.0.0.1:${port}:8765`,
    '--volume',
    `${root}:/app:ro`,
    '--volume',
    `${storage}:/app/build/tmp`,
    ...(process.getuid
      ? ['--user', `${process.getuid()}:${process.getgid()}`]
      : []),
    ...Object.keys(environment).flatMap((key) => ['--env', key]),
    image,
  ],
  {
    stdio: 'inherit',
    env: { ...process.env, ...environment },
  },
);
const stop = () => {
  void execute('docker', ['stop', '--time', '3', name]).catch(() => {});
};
process.once('SIGINT', stop);
process.once('SIGTERM', stop);
try {
  console.log(`Realtime demo: http://127.0.0.1:${port}/realtime`);
  console.log(`Share the app and its hub together: ngrok http ${port}`);
  const [code] = await once(preview, 'exit');
  process.exitCode = typeof code === 'number' ? code : 0;
} finally {
  process.removeListener('SIGINT', stop);
  process.removeListener('SIGTERM', stop);
  await execute('docker', ['rm', '--force', '--volumes', name]).catch(() => {});
}
