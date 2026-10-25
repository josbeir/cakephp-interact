import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { mkdir, mkdtemp, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { test as base } from '@playwright/test';

export { expect } from '@playwright/test';

/** Call the shared PHP launcher without duplicating its Docker configuration. */
async function preview(...arguments_: string[]): Promise<string> {
  const result = await promisify(execFile)(
    'php',
    ['scripts/preview.php', ...arguments_],
    {
      maxBuffer: 4 * 1024 * 1024,
    },
  );
  return result.stdout;
}

/**
 * Give each worker one isolated FrankenPHP app with bundled Mercure and disposable storage.
 *
 * The demo and tests share the same launcher. Concurrent requests use FrankenPHP's
 * ordinary PHP threads; SQLite, sessions, uploads, and hub credentials remain worker-owned.
 */
export const test = base.extend<
  object,
  {
    appURL: string;
    appServer: {
      name: string;
      url: string;
      restart(): Promise<void>;
      logs(): Promise<string>;
    };
  }
>({
  appServer: [
    async ({ browserName }, use, workerInfo) => {
      const storage = await mkdtemp(
        join(tmpdir(), `interact-playwright-${browserName}-`),
      );
      let name: string | undefined;
      try {
        const server: unknown = JSON.parse(await preview('start', storage));
        if (
          !server ||
          typeof server !== 'object' ||
          !('name' in server) ||
          typeof server.name !== 'string' ||
          !('url' in server) ||
          typeof server.url !== 'string'
        ) {
          throw new Error('Invalid preview server handle.');
        }
        name = server.name;
        const container = name;
        await use({
          name,
          url: server.url,
          logs: () => preview('logs', container),
          restart: async () => {
            await preview('restart', container);
          },
        });
      } finally {
        try {
          if (name) {
            try {
              await mkdir(workerInfo.project.outputDir, { recursive: true });
              await writeFile(
                join(
                  workerInfo.project.outputDir,
                  `worker-${workerInfo.workerIndex}.log`,
                ),
                await preview('logs', name),
              );
            } finally {
              await preview('stop', name);
            }
          }
        } finally {
          await rm(storage, { recursive: true, force: true });
        }
      }
    },
    { scope: 'worker' },
  ],
  appURL: [
    async ({ appServer }, use) => use(appServer.url),
    { scope: 'worker' },
  ],
  baseURL: async ({ appURL }, use) => use(appURL),
});
