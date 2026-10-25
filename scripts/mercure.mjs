import { createServer } from 'node:net';
import { Buffer } from 'node:buffer';
import { execFile } from 'node:child_process';
import { createHmac, randomBytes, randomUUID } from 'node:crypto';
import { promisify } from 'node:util';
import { URL, fileURLToPath } from 'node:url';
import { setTimeout } from 'node:timers/promises';

const execute = promisify(execFile);
export const mercureImage =
  'dunglas/mercure:v1.1.4@sha256:ed2c3959516e9945542cdc9888df01222b48b56dabf0407834b1808bccb78574';

/** Start an isolated real Mercure 1.x hub for isolated browser fixtures. */
export async function startMercure() {
  const name = `interact-mercure-${randomUUID()}`;
  const secret = randomBytes(32).toString('hex');
  const config = fileURLToPath(
    new URL('../tests/Fixture/mercure/Caddyfile', import.meta.url),
  );
  const logs = async () => {
    const result = await execute('docker', ['logs', name]);
    return result.stdout + result.stderr;
  };
  const stop = async () => {
    await execute('docker', ['rm', '--force', '--volumes', name]);
  };
  const reservation = createServer();
  await new Promise((resolve) => reservation.listen(0, '127.0.0.1', resolve));
  const reserved = reservation.address();
  if (!reserved || typeof reserved === 'string')
    throw new Error('Cannot allocate a Mercure port.');
  const port = reserved.port;
  await new Promise((resolve, reject) =>
    reservation.close((error) => (error ? reject(error) : resolve())),
  );
  let created = false;
  try {
    await execute('docker', [
      'create',
      '--name',
      name,
      '--publish',
      `127.0.0.1:${port}:80`,
      '--env',
      `MERCURE_TEST_SECRET=${secret}`,
      mercureImage,
    ]);
    created = true;
    await execute('docker', ['cp', config, `${name}:/etc/caddy/Caddyfile`]);
    await execute('docker', ['start', name]);
    const { stdout } = await execute('docker', ['port', name, '80/tcp']);
    const address = stdout.trim().split('\n')[0];
    if (!address?.match(/^127\.0\.0\.1:\d+$/))
      throw new Error('Mercure did not publish a loopback port.');
    const url = `http://${address}/.well-known/mercure`;
    const issuer = 'https://localhost';
    const audience = 'https://interact.test/.well-known/mercure';
    const tokenPart = (value) =>
      Buffer.from(JSON.stringify(value)).toString('base64url');
    const now = Math.floor(Date.now() / 1000);
    const unsigned = `${tokenPart({ alg: 'HS256', typ: 'at+jwt' })}.${tokenPart({ iss: issuer, aud: audience, sub: issuer, client_id: issuer, iat: now, exp: now + 60, jti: randomUUID(), authorization_details: [{ type: 'https://mercure.rocks/authorization-detail', actions: ['subscribe'], topics: [{ match: 'readiness' }] }] })}`;
    const token = `${unsigned}.${createHmac('sha256', secret).update(unsigned).digest('base64url')}`;
    let ready = false;
    for (let attempt = 0; attempt < 100; attempt++) {
      try {
        const response = await globalThis.fetch(`${url}?match=readiness`, {
          headers: { Authorization: `Bearer ${token}` },
          signal: globalThis.AbortSignal.timeout(1000),
        });
        ready = response.ok;
        await response.body?.cancel();
        if (ready) break;
      } catch {
        /* Startup is bounded by the readiness loop. */
      }
      await setTimeout(50);
    }
    if (!ready)
      throw new Error(`Mercure did not become ready.\n${await logs()}`);
    return {
      url,
      name,
      env: {
        MERCURE_URL: url,
        MERCURE_PUBLIC_URL: url,
        MERCURE_JWT_SECRET: secret,
        MERCURE_JWT_ISSUER: issuer,
        MERCURE_JWT_AUDIENCE: audience,
      },
      logs,
      restart: async () => {
        await execute('docker', ['restart', '--time', '0', name]);
      },
      stop,
    };
  } catch (error) {
    if (created) await stop().catch(() => {});
    throw new Error(
      'Cannot start the Mercure test hub. Check Docker access and image availability.',
      { cause: error },
    );
  }
}
