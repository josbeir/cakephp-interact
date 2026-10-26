import type { Page, Locator } from '@playwright/test';
import { expect, test } from './fixtures';
import { targetsIsland } from './requests';

function child(page: Page, name: string): Locator {
  return page
    .getByRole('region', { name, exact: true })
    .locator('[data-interact-id]')
    .first();
}
async function id(root: Locator): Promise<string> {
  const value = await root.getAttribute('data-interact-id');
  if (!value) throw new Error('Missing report identity.');
  return value;
}
async function role(page: Page, value: string) {
  const token = await page
    .locator('[data-interact-csrf]')
    .first()
    .getAttribute('data-interact-csrf');
  if (!token) throw new Error('Missing CSRF token.');
  const response = await page.request.post(`/demo/role/${value}`, {
    headers: { 'X-CSRF-Token': token },
  });
  expect(response.ok()).toBe(true);
}

test('loads deferred, manual, hidden, and below-fold reports through their own triggers', async ({
  page,
}) => {
  const errors: string[] = [];
  page.on('pageerror', (error) => errors.push(error.message));
  await page.goto('/loading');
  await expect(
    page.getByRole('region', { name: 'Deferred resource report', exact: true }),
  ).toBeVisible();
  const manual = child(page, 'Manual report');
  const lazy = child(page, 'Lazy report');
  const hidden = child(page, 'Hidden report');
  await expect(manual).toHaveAttribute('data-interact-loading', 'manual');
  await expect(lazy).toHaveAttribute('data-interact-loading', 'lazy');
  await expect(hidden).toHaveAttribute('data-interact-loading', 'lazy');
  await page.getByRole('button', { name: 'Load report', exact: true }).click();
  await expect(manual).not.toHaveAttribute('data-interact-loading');
  await expect(manual).toContainText(/1,?200 matching resources/);
  await manual
    .getByRole('searchbox', { name: 'Search report' })
    .fill('security');
  await manual
    .getByRole('button', { name: 'Refresh report', exact: true })
    .click();
  await expect(manual).toContainText('Refreshed 1 times');
  await expect(manual).toContainText('Mounted 1 time');
  await page.getByRole('button', { name: 'Toggle report panel' }).click();
  await expect(hidden).toBeVisible();
  // Opening the panel can leave its lazy placeholder below the viewport.
  await hidden.scrollIntoViewIfNeeded();
  await expect(hidden).not.toHaveAttribute('data-interact-loading');
  await lazy.scrollIntoViewIfNeeded();
  await expect(lazy).not.toHaveAttribute('data-interact-loading');
  expect(errors).toEqual([]);
});

test('parent refresh preserves a waiting placeholder and an in-flight initialization', async ({
  page,
}) => {
  await page.goto('/loading');
  const manual = child(page, 'Manual report');
  const identity = await id(manual);
  const snapshot = await manual.getAttribute('data-interact-snapshot');
  await page
    .getByRole('button', { name: 'Refresh dashboard', exact: true })
    .click();
  await expect(
    page.getByRole('region', { name: 'Loading dashboard', exact: true }),
  ).toContainText('Parent refreshed 1 times');
  await expect(manual).toHaveAttribute(
    'data-interact-snapshot',
    snapshot ?? '',
  );
  let release: (() => void) | undefined;
  let started: (() => void) | undefined;
  const waiting = new Promise<void>((resolve) => {
    started = resolve;
  });
  const gate = new Promise<void>((resolve) => {
    release = resolve;
  });
  let requests = 0;
  await page.route('**/_interact/update', async (route) => {
    if (!targetsIsland(route.request(), identity)) return route.continue();
    requests++;
    started?.();
    const response = await route.fetch();
    await gate;
    await route.fulfill({ response });
  });
  await manual
    .getByRole('button', { name: 'Load report', exact: true })
    .click();
  await waiting;
  await page
    .getByRole('button', { name: 'Refresh dashboard', exact: true })
    .click();
  await expect(
    page.getByRole('region', { name: 'Loading dashboard', exact: true }),
  ).toContainText('Parent refreshed 2 times');
  release?.();
  await expect(manual).not.toHaveAttribute('data-interact-loading');
  expect(requests).toBe(1);
  await expect(manual).toHaveAttribute('data-interact-id', identity);
});

test('failed automatic loads show recovery and wait for an explicit retry', async ({
  page,
}) => {
  let failures = 0;
  await page.route('**/_interact/update', async (route) => {
    if (route.request().postDataJSON().initialize === true && failures++ === 0)
      return route.fulfill({ status: 503, body: 'Unavailable' });
    await route.continue();
  });
  await page.goto('/loading');
  const deferred = child(page, 'Deferred report');
  await expect(deferred.getByRole('alert')).toContainText('server');
  await expect(
    deferred.getByRole('button', { name: 'Try again' }),
  ).toBeVisible();
  await deferred.getByRole('button', { name: 'Try again' }).click();
  await expect(deferred).not.toHaveAttribute('data-interact-loading');
  expect(failures).toBe(2);
});

test('checks current authorization when a user loads a waiting report', async ({
  page,
}) => {
  await page.goto('/loading');
  const manual = child(page, 'Manual report');
  await role(page, 'viewer');
  await manual
    .getByRole('button', { name: 'Load report', exact: true })
    .click();
  await expect(manual.getByRole('alert')).toContainText('permission');
  await expect(manual).toHaveAttribute('data-interact-loading', 'manual');
  await expect(
    manual.getByRole('searchbox', { name: 'Search report' }),
  ).toHaveCount(0);
});

test('removing a loading child ignores its late response and restoration makes a fresh placeholder', async ({
  page,
}) => {
  await page.goto('/loading');
  const manual = child(page, 'Manual report');
  const identity = await id(manual);
  let release: (() => void) | undefined;
  let received: (() => void) | undefined;
  const gate = new Promise<void>((resolve) => {
    release = resolve;
  });
  const waiting = new Promise<void>((resolve) => {
    received = resolve;
  });
  await page.route('**/_interact/update', async (route) => {
    if (!targetsIsland(route.request(), identity)) return route.continue();
    const response = await route.fetch();
    received?.();
    await gate;
    await route.fulfill({ response }).catch(() => {});
  });
  await manual
    .getByRole('button', { name: 'Load report', exact: true })
    .click();
  await waiting;
  await page.getByRole('button', { name: 'Remove manual report' }).click();
  await expect(
    page.getByRole('region', { name: 'Manual report', exact: true }),
  ).toHaveCount(0);
  release?.();
  await page.getByRole('button', { name: 'Restore manual report' }).click();
  await expect(manual).toHaveAttribute('data-interact-loading', 'manual');
  expect(await id(manual)).not.toBe(identity);
});

test('uses current URL input and preserves a newer history selection during loading', async ({
  page,
}) => {
  await page.goto('/loading?manual_q=first');
  const manual = child(page, 'Manual report');
  const identity = await id(manual);
  let release: (() => void) | undefined;
  let received: (() => void) | undefined;
  const gate = new Promise<void>((resolve) => {
    release = resolve;
  });
  const waiting = new Promise<void>((resolve) => {
    received = resolve;
  });
  const requests: boolean[] = [];
  await page.route('**/_interact/update', async (route) => {
    if (!targetsIsland(route.request(), identity)) return route.continue();
    const initializing = route.request().postDataJSON().initialize === true;
    requests.push(initializing);
    const response = await route.fetch();
    if (initializing) {
      received?.();
      await gate;
    }
    await route.fulfill({ response });
  });
  await manual
    .getByRole('button', { name: 'Load report', exact: true })
    .click();
  await waiting;
  await page.evaluate(() => {
    history.replaceState(
      { application: true },
      '',
      '/loading?manual_q=security',
    );
    dispatchEvent(new PopStateEvent('popstate'));
  });
  release?.();
  await expect(
    manual.getByRole('searchbox', { name: 'Search report' }),
  ).toHaveValue('security');
  await expect(manual).not.toHaveAttribute('aria-busy', 'true');
  expect(requests).toEqual([true, false]);
  expect(new URL(page.url()).searchParams.get('manual_q')).toBe('security');
});

test('starts polling only from loaded content and preserves local Alpine state', async ({
  page,
}) => {
  await page.clock.install({ time: new Date('2026-10-21T12:00:00Z') });
  await page.clock.pauseAt(new Date('2026-10-21T12:00:01Z'));
  await page.goto('/loading');
  const manual = child(page, 'Manual report');
  const identity = await id(manual);
  let polls = 0;
  page.on('request', (request) => {
    if (
      targetsIsland(request, identity) &&
      request.postDataJSON().initialize !== true
    )
      polls++;
  });
  await page.clock.runFor(10000);
  expect(polls).toBe(0);
  await manual
    .getByRole('button', { name: 'Load report', exact: true })
    .click();
  await expect(manual).not.toHaveAttribute('data-interact-loading');
  const automatic = manual.getByRole('checkbox', {
    name: 'Automatically refresh report',
  });
  await automatic.check();
  const response = page.waitForResponse((reply) =>
    targetsIsland(reply.request(), identity),
  );
  await page.clock.runFor(5000);
  expect((await response).status()).toBe(200);
  await expect(manual).not.toHaveAttribute('aria-busy', 'true');
  await expect(automatic).toBeChecked();
  expect(polls).toBe(1);
  await expect(manual).toContainText('Mounted 1 time');
});

test('activates upload declarations and native FormHelper bindings after loading', async ({
  page,
}) => {
  const errors: string[] = [];
  page.on('pageerror', (error) => errors.push(error.message));
  await page.goto('/demo/loading-upload');
  await page.getByRole('button', { name: 'Load content', exact: true }).click();
  await page.getByLabel('Document title').fill('Deferred upload');
  const uploaded = page.waitForResponse((response) => {
    const contentType = response.request().headers()['content-type'];
    return contentType?.startsWith('multipart/form-data') === true;
  });
  await page.getByLabel('Main document', { exact: true }).setInputFiles({
    name: 'deferred.txt',
    mimeType: 'text/plain',
    buffer: Buffer.from('Loaded upload'),
  });
  expect((await uploaded).status()).toBe(200);
  await expect(
    page.getByRole('list', { name: 'Main document files', exact: true }),
  ).toContainText('deferred.txt');
  await page
    .getByRole('button', { name: 'Save documents', exact: true })
    .click();
  await expect(page.getByRole('status')).toContainText('Saved 1 file(s)');
  expect(errors).toEqual([]);
});
