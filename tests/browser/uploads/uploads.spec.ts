import { expect, test } from '../fixtures';
import type { Page, Request, Route } from '@playwright/test';

const file = (name = 'notes.txt', content = 'A real text document') => ({
  name,
  mimeType: 'text/plain',
  buffer: Buffer.from(content),
});
const transfer = (request: Request) =>
  request.url().endsWith('/uploads/update') &&
  request.headers()['content-type']?.startsWith('multipart/form-data') === true;
const update = (request: Request) =>
  request.url().endsWith('/uploads/update') && !transfer(request);

async function choose(page: Page, name = 'notes.txt', field = 'Main document') {
  const accepted = page.waitForResponse((response) =>
    transfer(response.request()),
  );
  await page.getByLabel(field, { exact: true }).setInputFiles(file(name));
  const response = await accepted;
  expect(response.status()).toBe(200);
  expect(response.headers()['interact']).toBe('true');
  expect(response.request().headers()['interact']).toBe('true');
  await expect(
    page.getByRole('list', { name: `${field} files`, exact: true }),
  ).toContainText(name);
}

test('PHP renders accepted files and the application saves them through its custom endpoint', async ({
  page,
}) => {
  const errors: string[] = [];
  page.on('pageerror', (error) => errors.push(error.message));
  await page.goto('/uploads');
  await page.getByLabel('Document title').fill('Release notes');
  await choose(page);
  expect(
    await page
      .getByLabel('Main document', { exact: true })
      .evaluate((input: HTMLInputElement) => input.required),
  ).toBe(false);
  const response = page.waitForResponse((response) =>
    update(response.request()),
  );
  await page
    .getByRole('button', { name: 'Save documents', exact: true })
    .click();
  expect((await response).status()).toBe(200);
  await expect(page.getByRole('status')).toContainText('Saved 1 file(s)');
  await expect(
    page
      .getByRole('list', { name: 'Main document files', exact: true })
      .getByRole('listitem'),
  ).toHaveCount(0);
  expect(
    await page
      .getByLabel('Main document', { exact: true })
      .evaluate((input: HTMLInputElement) => input.required),
  ).toBe(true);
  expect(errors).toEqual([]);
});

test('application validation keeps accepted files and ordinary drafts without uploading again', async ({
  page,
}) => {
  let transfers = 0;
  page.on('request', (request) => {
    if (transfer(request)) transfers++;
  });
  await page.goto('/uploads');
  await choose(page);
  await page
    .locator('#document-upload-form')
    .evaluate((form: HTMLFormElement) => {
      form.noValidate = true;
    });
  const failed = page.waitForResponse((response) => update(response.request()));
  await page
    .getByRole('button', { name: 'Save documents', exact: true })
    .click();
  expect((await failed).status()).toBe(422);
  await expect(
    page.getByText('Enter a document title.', { exact: true }),
  ).toBeVisible();
  await expect(
    page.getByRole('list', { name: 'Main document files', exact: true }),
  ).toContainText('notes.txt');
  await page.getByLabel('Document title').fill('Corrected title');
  await page
    .getByRole('button', { name: 'Save documents', exact: true })
    .click();
  await expect(page.getByRole('status')).toContainText('Saved 1 file(s)');
  expect(transfers).toBe(1);
});

test('multiple chooser selections append and PHP-rendered removal targets the selected attachment', async ({
  page,
}) => {
  await page.goto('/uploads');
  await choose(page, 'first.txt', 'Attachments');
  await choose(page, 'second.txt', 'Attachments');
  const files = page.getByRole('list', {
    name: 'Attachments files',
    exact: true,
  });
  await expect(files.getByRole('listitem')).toHaveCount(2);
  const removed = page.waitForResponse((response) =>
    update(response.request()),
  );
  await files
    .getByRole('listitem')
    .filter({ hasText: 'first.txt' })
    .getByRole('button', { name: 'Remove', exact: true })
    .click();
  expect((await removed).status()).toBe(200);
  await expect(files).not.toContainText('first.txt');
  await expect(files).toContainText('second.txt');
});

test('a newer single-file selection replaces its predecessor and names remain escaped', async ({
  page,
}) => {
  await page.goto('/uploads');
  await choose(page, 'first.txt');
  await choose(page, '<img src=x onerror=alert(1)>.txt');
  const files = page.getByRole('list', {
    name: 'Main document files',
    exact: true,
  });
  await expect(files.getByRole('listitem')).toHaveCount(1);
  await expect(files).not.toContainText('first.txt');
  await expect(files.locator('img')).toHaveCount(0);
  await files.getByRole('button', { name: 'Remove', exact: true }).click();
  await expect(files.getByRole('listitem')).toHaveCount(0);
});

test('submitting while transferring waits and preserves typing made during the request', async ({
  page,
}) => {
  let held: Route | undefined;
  let release: (() => void) | undefined;
  const waiting = new Promise<void>((resolve) => {
    release = resolve;
  });
  await page.route('**/uploads/update', async (route) => {
    if (transfer(route.request())) {
      held = route;
      await waiting;
    }
    await route.continue();
  });
  await page.goto('/uploads');
  await page.getByLabel('Document title').fill('Before transfer');
  await page.getByLabel('Main document', { exact: true }).setInputFiles(file());
  await expect.poll(() => Boolean(held)).toBe(true);
  await page
    .getByRole('button', { name: 'Save documents', exact: true })
    .click();
  await expect(
    page.getByRole('button', { name: 'Save documents', exact: true }),
  ).toBeDisabled();
  await page.getByLabel('Document title').fill('Typed during transfer');
  const saved = page.waitForResponse((response) => update(response.request()));
  release?.();
  expect((await saved).status()).toBe(200);
  await expect(page.getByRole('status')).toContainText('Saved 1 file(s)');
  await expect(page.getByLabel('Document title')).toHaveValue(
    'Typed during transfer',
  );
});

test('cancellation discards the queued save without turning into a request failure', async ({
  page,
}) => {
  let intercepted = false;
  let release: (() => void) | undefined;
  const waiting = new Promise<void>((resolve) => {
    release = resolve;
  });
  let saves = 0;
  page.on('request', (request) => {
    if (update(request)) saves++;
  });
  await page.route('**/uploads/update', async (route) => {
    if (transfer(route.request())) {
      intercepted = true;
      await waiting;
      await route.abort('failed');
    } else await route.continue();
  });
  await page.goto('/uploads');
  await page.getByLabel('Document title').fill('Cancelled document');
  await page.getByLabel('Main document', { exact: true }).setInputFiles(file());
  await expect.poll(() => intercepted).toBe(true);
  await page
    .getByRole('button', { name: 'Save documents', exact: true })
    .click();
  await page
    .getByRole('list', { name: 'Main document transfers', exact: true })
    .getByRole('button', { name: 'Cancel', exact: true })
    .click();
  release?.();
  await expect(
    page.getByRole('button', { name: 'Save documents', exact: true }),
  ).toBeEnabled();
  await expect(
    page
      .getByRole('list', { name: 'Main document transfers', exact: true })
      .getByRole('listitem'),
  ).toHaveCount(0);
  await expect(page.getByRole('alert', { includeHidden: true })).toBeEmpty();
  expect(saves).toBe(0);
});

test('a failed transfer requires an explicit retry and does not automatically save', async ({
  page,
}) => {
  let failed = false;
  let saves = 0;
  page.on('request', (request) => {
    if (update(request)) saves++;
  });
  await page.route('**/uploads/update', async (route) => {
    if (transfer(route.request()) && !failed) {
      failed = true;
      await route.abort('failed');
    } else await route.continue();
  });
  await page.goto('/uploads');
  await page.getByLabel('Document title').fill('Retry document');
  await page.getByLabel('Main document', { exact: true }).setInputFiles(file());
  const transfers = page.getByRole('list', {
    name: 'Main document transfers',
    exact: true,
  });
  await expect(transfers).toContainText('failed');
  const chooser = page.getByLabel('Main document', { exact: true });
  await expect(chooser).toHaveJSProperty('required', true);
  expect(
    await chooser.evaluate((input: HTMLInputElement) => input.checkValidity()),
  ).toBe(false);
  await transfers.getByRole('button', { name: 'Retry', exact: true }).click();
  await expect(
    page.getByRole('list', { name: 'Main document files', exact: true }),
  ).toContainText('notes.txt');
  await expect(chooser).toHaveJSProperty('required', false);
  expect(
    await chooser.evaluate((input: HTMLInputElement) => input.checkValidity()),
  ).toBe(true);
  expect(saves).toBe(0);
  await page
    .getByRole('button', { name: 'Save documents', exact: true })
    .click();
  await expect(page.getByRole('status')).toContainText('Saved 1 file(s)');
  expect(saves).toBe(1);
});

test('the field size ceiling rejects oversized files without starting a transfer', async ({
  page,
}) => {
  let transfers = 0;
  page.on('request', (request) => {
    if (transfer(request)) transfers++;
  });
  await page.goto('/uploads');
  for (const size of [1024 * 1024 + 1, 12 * 1024 * 1024]) {
    await page.getByLabel('Main document', { exact: true }).setInputFiles({
      name: 'large.txt',
      mimeType: 'text/plain',
      buffer: Buffer.alloc(size),
    });
    await expect(page.getByRole('alert')).toContainText(
      'Choose a file no larger than 1 MiB.',
    );
    await expect(
      page.getByRole('list', { name: 'Main document transfers', exact: true }),
    ).toContainText('failed');
    expect(transfers).toBe(0);
  }
  await choose(page, 'smaller.txt');
  await expect(page.getByRole('alert', { includeHidden: true })).toBeEmpty();
});

test('a batch respects the field count and failed excess files can be retried after removal', async ({
  page,
}) => {
  let transfers = 0;
  page.on('request', (request) => {
    if (transfer(request)) transfers++;
  });
  await page.goto('/uploads');
  await page
    .getByLabel('Attachments', { exact: true })
    .setInputFiles([
      file('one.txt'),
      file('two.txt'),
      file('three.txt'),
      file('four.txt'),
    ]);
  const accepted = page.getByRole('list', {
    name: 'Attachments files',
    exact: true,
  });
  const unfinished = page.getByRole('list', {
    name: 'Attachments transfers',
    exact: true,
  });
  await expect(accepted.getByRole('listitem')).toHaveCount(3);
  await expect(unfinished).toContainText('failed');
  await expect(page.getByRole('alert')).toContainText(
    'Remove an attachment before adding another file.',
  );
  expect(transfers).toBe(3);
  await accepted
    .getByRole('listitem')
    .filter({ hasText: 'one.txt' })
    .getByRole('button', { name: 'Remove', exact: true })
    .click();
  await expect(accepted.getByRole('listitem')).toHaveCount(2);
  await unfinished.getByRole('button', { name: 'Retry', exact: true }).click();
  await expect(accepted).toContainText('four.txt');
  await expect(accepted.getByRole('listitem')).toHaveCount(3);
  await expect(page.getByRole('alert', { includeHidden: true })).toBeEmpty();
  expect(transfers).toBe(4);
});

test('PHP body-size rejection remains specific when the multipart envelope is discarded', async ({
  page,
  appServer,
}) => {
  await page.goto('/uploads');
  const { endpoint, csrf, snapshot } = await page
    .locator('[data-interact-id]')
    .evaluate((root: HTMLElement) => {
      const endpoint = root.dataset.interactEndpoint;
      const csrf = root.dataset.interactCsrf;
      if (!endpoint || !csrf) throw new Error('Missing upload metadata');
      return { endpoint, csrf, snapshot: root.dataset.interactSnapshot };
    });
  // PHP rejects the envelope before reading its body. Browser uploads can reset
  // that HTTP/1 connection; use the shared-cookie HTTP client for this server contract.
  const response = await page.request.post(endpoint, {
    headers: { 'X-CSRF-Token': csrf, Accept: 'application/json' },
    multipart: {
      payload: JSON.stringify({
        version: 1,
        snapshot,
        upload: { field: 'document', kind: 'stage' },
      }),
      file: {
        name: 'large.txt',
        mimeType: 'text/plain',
        buffer: Buffer.alloc(12 * 1024 * 1024),
      },
    },
  });
  expect(response.status()).toBe(413);
  await expect.poll(() => appServer.logs()).toContain('POST Content-Length');
  const body: unknown = await response.json();
  expect(body).toMatchObject({
    version: 1,
    error: {
      code: 'upload_too_large',
      message: expect.stringContaining('size limit'),
    },
  });
});
