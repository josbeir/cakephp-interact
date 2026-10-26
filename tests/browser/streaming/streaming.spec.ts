import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { expect, test } from '../fixtures';

/** Release the PHP action only after the browser has observed its incremental output. */
async function release(container: string, address: string): Promise<void> {
  await promisify(execFile)('docker', [
    'exec',
    container,
    'php',
    '-r',
    '$connection = stream_socket_client($argv[1]); if ($connection === false) { exit(1); } fclose($connection);',
    `tcp://${address}`,
  ]);
}

test('receives text, escaped elements, and progress before completion while releasing the session', async ({
  page,
  appServer,
}) => {
  const errors: string[] = [];
  page.on('pageerror', (error) => errors.push(error.message));
  await page.goto('/demo/streaming-probe');
  const before = await page.request.get('/demo/streaming-session');
  const identity: unknown = await before.json();
  const button = page.getByRole('button', { name: 'Start gated stream' });
  await button.click();
  const gate = page.locator('[data-gate-address]');
  await expect(gate).toHaveAttribute('data-gate-address', /127\.0\.0\.1:\d+/);
  const address = await gate.getAttribute('data-gate-address');
  if (!address) throw new Error('Missing streaming gate');
  try {
    await expect(page.locator('pre')).toHaveText('Partial <text> €');
    await expect(page.getByRole('listitem')).toHaveText('<escaped>');
    await expect(page.locator('pre script')).toHaveCount(0);
    await expect(button).toBeDisabled();
    // Another FrankenPHP thread serves the same session while the stream remains open.
    const concurrent = await page.request.get('/demo/streaming-session', {
      timeout: 5000,
    });
    expect(await concurrent.json()).toEqual(identity);
  } finally {
    await release(appServer.name, address);
  }
  await expect(page.locator('pre')).toHaveText('Complete');
  await expect(button).toBeEnabled();
  expect(errors).toEqual([]);
});

test('finishes a normal streaming action with authoritative text and element markup', async ({
  page,
}) => {
  await page.goto('/streaming');
  const response = page.waitForResponse((response) =>
    response.url().endsWith('/_interact/update'),
  );
  const partial = page.evaluate(
    () =>
      new Promise<{ text: string; busy: boolean; rows: number }>((resolve) => {
        const receive = (event: Event) => {
          if (
            !(event instanceof CustomEvent) ||
            event.detail.name !== 'progress' ||
            event.detail.detail.percent !== 33
          )
            return;
          window.removeEventListener('interact:stream', receive);
          resolve({
            text:
              document.querySelector('.stream-transcript')?.textContent ?? '',
            busy:
              document.querySelector('.stream-run')?.hasAttribute('disabled') ??
              false,
            rows: document.querySelectorAll('.stream-result').length,
          });
        };
        window.addEventListener('interact:stream', receive);
      }),
  );
  await page.getByRole('button', { name: 'Generate report' }).click();
  const stream = await response;
  expect(stream.headers()['content-type']).toContain('text/event-stream');
  expect(stream.headers()['interact']).toBe('true');
  expect(stream.request().headers()['interact']).toBe('true');
  const first = await partial;
  expect(first.text).toContain('CakePHP templates');
  expect(first.text).not.toContain('Signed final state');
  expect(first.busy).toBe(true);
  expect(first.rows).toBe(1);
  await expect(
    page.getByRole('progressbar', { name: 'Report progress' }),
  ).toHaveAttribute('value', '100');
  await expect(page.getByRole('listitem')).toHaveCount(3);
  await expect(page.locator('pre')).toContainText('Signed final state');
  await expect(
    page.getByRole('button', { name: 'Generate report' }),
  ).toBeEnabled();
  await expect(page.getByRole('listitem').nth(1)).toContainText(
    'Escaped <application> values',
  );
  await expect(page.getByRole('status')).toHaveText('Report complete');
  await page.setViewportSize({ width: 390, height: 844 });
  await expect(
    page.getByRole('button', { name: 'Generate report' }),
  ).toBeVisible();
  expect(
    await page.evaluate(() => document.documentElement.scrollWidth),
  ).toBeLessThanOrEqual(390);
});
