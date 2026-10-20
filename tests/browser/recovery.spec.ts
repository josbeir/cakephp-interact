import { createHmac } from 'node:crypto';
import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

function record(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}
async function recovery(page: Page) {
  await page.goto('/playground');
  return page.getByRole('region', { name: 'Failure recovery', exact: true });
}

test.beforeEach(async ({ page }) => {
  await page.clock.install();
});

test('a real server exception pauses typing and a new explicit sync preserves the draft', async ({
  page,
}) => {
  const region = await recovery(page);
  const pageErrors: string[] = [];
  page.on('pageerror', (error) => pageErrors.push(error.message));
  let requests = 0;
  page.on('request', (request) => {
    if (request.url().endsWith('/_interact/update')) requests++;
  });
  await region
    .getByLabel('Recovery draft', { exact: true })
    .fill('before failure');
  await region
    .getByRole('button', { name: 'Simulate server failure', exact: true })
    .click();
  await expect(region.getByRole('alert')).toContainText(
    'Check the result before trying again',
  );
  await expect(region).not.toContainText('Private fixture diagnostics');
  const sent = requests;
  await region
    .getByLabel('Recovery draft', { exact: true })
    .fill('newest local draft');
  await page.clock.runFor(1000);
  expect(requests).toBe(sent);
  await expect(region.getByLabel('Draft export')).toHaveValue(
    /newest local draft/,
  );
  await region
    .getByRole('button', { name: 'Synchronize latest draft' })
    .click();
  await expect(region.getByRole('alert')).toBeHidden();
  await expect(
    region.getByLabel('Recovery draft', { exact: true }),
  ).toHaveValue('newest local draft');
  await region.getByRole('button', { name: 'Accept draft' }).click();
  await expect(region.getByRole('status')).toHaveText('Accepted 1 times.');
  expect(pageErrors).toEqual([]);
});

test('expired signed snapshots require a fresh mount without clearing local edits', async ({
  page,
}) => {
  const region = await recovery(page);
  await page.route('**/_interact/update', async (route) => {
    const data: unknown = route.request().postDataJSON();
    if (!record(data) || typeof data.snapshot !== 'string')
      throw new Error('Missing fixture snapshot');
    const envelope: unknown = JSON.parse(
      Buffer.from(data.snapshot.split('.')[0] ?? '', 'base64url').toString(),
    );
    if (!record(envelope)) throw new Error('Invalid fixture envelope');
    envelope.expires = 1;
    const payload = Buffer.from(JSON.stringify(envelope)).toString('base64url');
    const key = createHmac(
      'sha256',
      'interact-demo-fixture-only-do-not-use-in-production',
    )
      .update('interact.snapshot.v1')
      .digest();
    const signature = createHmac('sha256', key).update(payload).digest('hex');
    await route.continue({
      postData: JSON.stringify({
        ...data,
        snapshot: payload + '.' + signature,
      }),
    });
  });
  let requests = 0;
  page.on('request', (request) => {
    if (request.url().endsWith('/_interact/update')) requests++;
  });
  await region
    .getByLabel('Recovery draft', { exact: true })
    .fill('keep my text');
  await region.getByRole('button', { name: 'Accept draft' }).click();
  await expect(region.getByRole('alert')).toContainText(
    'This page has expired',
  );
  const sent = requests;
  await region
    .getByLabel('Recovery draft', { exact: true })
    .fill('still editable');
  await region.getByRole('button', { name: 'Accept draft' }).click();
  await page.clock.runFor(1000);
  expect(requests).toBe(sent);
  await expect(region.getByLabel('Draft export')).toHaveValue(/still editable/);
  await expect(
    region.getByRole('link', { name: 'Reload page' }),
  ).toHaveAttribute('href', '/playground');
});

test('a changed session is distinguished from expiry', async ({
  page,
  context,
}) => {
  const region = await recovery(page);
  const cookies = await context.cookies();
  expect(cookies.some((cookie) => cookie.name === 'PHPSESSID')).toBe(true);
  await context.clearCookies({ name: 'PHPSESSID' });
  await region.getByRole('button', { name: 'Accept draft' }).click();
  await expect(region.getByRole('alert')).toContainText(
    'Your session has changed',
  );
});

test('actual middleware CSRF rejection retains the draft and blocks resubmission', async ({
  page,
}) => {
  const region = await recovery(page);
  await page.route('**/_interact/update', async (route) => {
    const headers = route.request().headers();
    delete headers['x-csrf-token'];
    await route.continue({ headers });
  });
  await region.getByLabel('Recovery draft', { exact: true }).fill('csrf draft');
  const response = page.waitForResponse('**/_interact/update');
  await region.getByRole('button', { name: 'Accept draft' }).click();
  const rejected = await response;
  expect(rejected.status()).toBe(403);
  expect(await rejected.json()).toMatchObject({
    version: 1,
    error: { code: 'csrf_failed' },
  });
  await expect(region.getByRole('alert')).toContainText('security token');
  await expect(region.getByLabel('Draft export')).toHaveValue(/csrf draft/);
});

for (const [name, status, body] of [
  ['network', 0, ''],
  ['HTML response', 200, '<h1>Private backend output</h1>'],
  ['redirect', 302, ''],
  ['authentication', 401, 'Private login error'],
] as const) {
  test(`${name} failure has safe feedback and preserves drafts`, async ({
    page,
  }) => {
    const region = await recovery(page);
    let requests = 0;
    let loginRequests = 0;
    page.on('request', (request) => {
      if (request.url().endsWith('/_interact/update')) requests++;
      if (request.url().endsWith('/login')) loginRequests++;
    });
    await page.route('**/_interact/update', async (route) => {
      if (status === 0) await route.abort('failed');
      else
        await route.fulfill({
          status,
          body,
          contentType: 'text/html',
          headers: status === 302 ? { Location: '/login' } : {},
        });
    });
    await region
      .getByLabel('Recovery draft', { exact: true })
      .fill('preserved text');
    await region.getByRole('button', { name: 'Accept draft' }).click();
    await expect(region.getByRole('alert')).toBeVisible();
    await expect(region.getByRole('alert')).not.toContainText('Private');
    const sent = requests;
    await region
      .getByLabel('Recovery draft', { exact: true })
      .fill('edited while paused');
    await page.clock.runFor(1000);
    expect(requests).toBe(sent);
    expect(loginRequests).toBe(0);
    await expect(region.getByLabel('Draft export')).toHaveValue(
      /edited while paused/,
    );
  });
}

test('permission changes after mounting reject the next save', async ({
  page,
}) => {
  await page.goto('/');
  const editor = page.getByRole('article', { name: 'Article 1', exact: true });
  const csrf = await editor
    .locator('xpath=..')
    .getAttribute('data-interact-csrf');
  if (csrf === null) throw new Error('Missing fixture CSRF token');
  const changed = await page.request.post('/demo/role/viewer', {
    headers: { 'X-CSRF-Token': csrf },
  });
  expect(changed.ok()).toBe(true);
  await editor.getByLabel('Article title').fill('permission draft');
  await editor.getByRole('button', { name: 'Save article' }).click();
  await expect(editor.getByRole('alert')).toContainText(
    'no longer have permission',
  );
  await expect(editor.getByLabel('Article title')).toHaveValue(
    'permission draft',
  );
});

test('a lost response after persistence never automatically repeats the save', async ({
  page,
}) => {
  await page.goto('/');
  const editor = page.getByRole('article', { name: 'Article 1', exact: true });
  const original = await editor.getByLabel('Article title').inputValue();
  let requests = 0;
  await page.route('**/_interact/update', async (route) => {
    requests++;
    await route.fetch();
    await route.abort('failed');
  });
  try {
    await editor
      .getByLabel('Article title')
      .fill('Saved before connection loss');
    await editor.getByRole('button', { name: 'Save article' }).click();
    await expect(editor.getByRole('alert')).toContainText('connection failed');
    await page.clock.runFor(1000);
    expect(requests).toBe(1);
    await page.unroute('**/_interact/update');
    await page.reload();
    await expect(
      page
        .getByRole('article', { name: 'Article 1', exact: true })
        .getByLabel('Article title'),
    ).toHaveValue('Saved before connection loss');
  } finally {
    await page.unroute('**/_interact/update');
    const restored = page.getByRole('article', {
      name: 'Article 1',
      exact: true,
    });
    await restored.getByLabel('Article title').fill(original);
    await restored.getByRole('button', { name: 'Save article' }).click();
    await expect(restored.getByText('Saved.', { exact: true })).toBeVisible();
  }
});
