import { spawn } from 'node:child_process';
import { once } from 'node:events';
import { mkdir, mkdtemp, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { createInterface } from 'node:readline';
import { test as base } from '@playwright/test';

export { expect } from '@playwright/test';

/**
 * Give each parallel worker its own real PHP application and disposable storage.
 *
 * PHP chooses a free port; SQLite, sessions, uploads, and documentation subprocesses
 * share only that worker's directory. Teardown stops PHP before removing its files.
 */
export const test = base.extend<object, { appURL: string; peerURL: string }>({
  appURL: [
    async ({ playwright }, use, workerInfo) => {
      const storage = await mkdtemp(join(tmpdir(), 'interact-playwright-'));
      const previousStorage = process.env.INTERACT_DEMO_TMP;
      process.env.INTERACT_DEMO_TMP = storage;
      const server = spawn(
        'php',
        [
          '-d',
          'opcache.enable=0',
          '-d',
          'upload_max_filesize=2M',
          '-d',
          'post_max_size=8M',
          '-d',
          `session.save_path=${storage}`,
          '-S',
          '127.0.0.1:0',
          '-t',
          'tests/test_app/webroot',
          'tests/test_app/webroot/index.php',
        ],
        { stdio: ['ignore', 'ignore', 'pipe'] },
      );
      const output = createInterface({ input: server.stderr });
      const diagnostics: string[] = [];
      let timeout: ReturnType<typeof setTimeout> | undefined;
      const ready = new Promise<string>((resolve, reject) => {
        timeout = setTimeout(
          () => reject(new Error('PHP server startup timed out.')),
          15000,
        );
        server.once('error', reject);
        server.once('exit', (code, signal) =>
          reject(
            new Error(
              `PHP server exited (${code ?? signal}).\n${diagnostics.join('\n')}`,
            ),
          ),
        );
        output.on('line', (line) => {
          if (!/\] 127\.0\.0\.1:\d+ (?:Accepted|Closing)$/.test(line))
            diagnostics.push(line);
          const address = line.match(
            /Development Server \((http:\/\/127\.0\.0\.1:\d+)\) started/,
          )?.[1];
          if (address) {
            clearTimeout(timeout);
            resolve(address);
          }
        });
      });
      try {
        const address = await ready;
        const health = await playwright.request.newContext({
          baseURL: address,
        });
        try {
          const response = await health.get('/');
          if (!response.ok())
            throw new Error(
              `PHP application failed to boot (${response.status()}).`,
            );
        } finally {
          await health.dispose();
        }
        await use(address);
      } finally {
        clearTimeout(timeout);
        if (
          server.pid !== undefined &&
          server.exitCode === null &&
          server.signalCode === null
        ) {
          const stopped = once(server, 'close');
          server.kill('SIGTERM');
          const force = setTimeout(() => {
            server.kill('SIGKILL');
          }, 5000);
          try {
            await stopped;
          } finally {
            clearTimeout(force);
          }
        }
        output.close();
        if (previousStorage === undefined) delete process.env.INTERACT_DEMO_TMP;
        else process.env.INTERACT_DEMO_TMP = previousStorage;
        try {
          await mkdir(workerInfo.project.outputDir, { recursive: true });
          await writeFile(
            join(
              workerInfo.project.outputDir,
              `worker-${workerInfo.workerIndex}.log`,
            ),
            diagnostics.join('\n'),
          );
        } finally {
          await rm(storage, { recursive: true, force: true });
        }
      }
    },
    { scope: 'worker' },
  ],
  peerURL: [
    async ({ appURL }, use) => {
      // Depend on appURL so the primary worker has already installed its isolated storage.
      if (!appURL) throw new Error('Missing primary application');
      const storage = process.env.INTERACT_DEMO_TMP;
      if (!storage) throw new Error('Missing isolated application storage');
      const server = spawn(
        'php',
        [
          '-d',
          `session.save_path=${storage}`,
          '-S',
          '127.0.0.1:0',
          '-t',
          'tests/test_app/webroot',
          'tests/test_app/webroot/index.php',
        ],
        { stdio: ['ignore', 'ignore', 'pipe'] },
      );
      const output = createInterface({ input: server.stderr });
      let timeout: ReturnType<typeof setTimeout> | undefined;
      try {
        const address = await new Promise<string>((resolve, reject) => {
          timeout = setTimeout(
            () => reject(new Error('Peer PHP startup timed out')),
            15000,
          );
          server.once('error', reject);
          server.once('exit', () =>
            reject(new Error('Peer PHP server exited')),
          );
          output.on('line', (line) => {
            const url = line.match(
              /Development Server \((http:\/\/127\.0\.0\.1:\d+)\) started/,
            )?.[1];
            if (url) {
              clearTimeout(timeout);
              resolve(url);
            }
          });
        });
        await use(address);
      } finally {
        clearTimeout(timeout);
        if (
          server.pid !== undefined &&
          server.exitCode === null &&
          server.signalCode === null
        ) {
          const stopped = once(server, 'close');
          server.kill('SIGTERM');
          const force = setTimeout(() => server.kill('SIGKILL'), 5000);
          try {
            await stopped;
          } finally {
            clearTimeout(force);
          }
        }
        output.close();
      }
    },
    { scope: 'worker' },
  ],
  baseURL: async ({ appURL }, use) => {
    await use(appURL);
  },
});
