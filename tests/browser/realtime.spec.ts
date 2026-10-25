import type { Page } from '@playwright/test';
import { test, expect } from './fixtures';

test.use({ realtime: true });

async function connected(page: Page, appURL: string) {
  await page.goto(`${appURL}/realtime`);
  await settled(page);
  await expect(
    page.getByText('connected', { exact: true }).first(),
  ).toBeVisible();
  await expect(
    page.getByRole('button', { name: 'Save article' }).first(),
  ).toBeEnabled();
}

async function settled(page: Page) {
  await expect
    .poll(() =>
      page
        .locator('[data-interact-id]')
        .evaluateAll(
          (roots) =>
            roots.length === 2 &&
            roots.every(
              (root) =>
                root instanceof HTMLElement &&
                window.Alpine?.evaluateRaw(root, '$server.realtime.status') ===
                  'connected' &&
                window.Alpine?.evaluateRaw(root, '$server.realtime.stale') ===
                  false &&
                window.Alpine?.evaluateRaw(root, '$server.busy') === false,
            ),
        ),
    )
    .toBe(true);
}

test('shares one Mercure connection across independent islands and exact topics', async ({
  page,
  appURL,
  mercureHub,
}) => {
  const errors: string[] = [];
  page.on('pageerror', (error) => errors.push(error.message));
  const subscriptions: string[] = [];
  page.on('request', (request) => {
    if (
      request.method() === 'GET' &&
      request.url().startsWith(mercureHub?.url ?? 'missing')
    )
      subscriptions.push(request.url());
  });
  await connected(page, appURL);
  await expect(page.getByText('connected', { exact: true })).toHaveCount(2);
  expect(subscriptions).toHaveLength(1);
  expect(new URL(subscriptions[0] ?? '').searchParams.getAll('match')).toEqual([
    'articles/1',
    'users/1/notifications',
    'users/1/output',
  ]);
  await page
    .getByRole('button', { name: 'Send private notification' })
    .first()
    .click();
  await expect(page.getByLabel('Private notification').first()).toHaveText(
    'An update just arrived.',
  );
  await expect(page.getByLabel('Private notification').last()).toHaveText(
    'An update just arrived.',
  );
  await page.getByRole('button', { name: 'Generate report' }).first().click();
  await expect(page.locator('pre').first()).toContainText('Report ready.');
  await expect(
    page.locator('li').filter({ hasText: 'Publishing snapshot' }),
  ).toHaveCount(1);
  await expect(
    page.getByRole('progressbar', { name: 'Report progress' }),
  ).toHaveAttribute('aria-valuenow', '100');
  expect(subscriptions).toHaveLength(1);
  expect(errors).toEqual([]);
});

test('refreshes another session while preserving an unrelated unsaved draft', async ({
  page,
  browser,
  appURL,
}) => {
  await connected(page, appURL);
  const context = await browser.newContext();
  try {
    const other = await context.newPage();
    await connected(other, appURL);
    const draft = other.getByLabel('Draft title').first();
    await draft.fill('Keep my unfinished draft');
    await page
      .getByLabel('Draft title')
      .first()
      .fill('Published from another session');
    await page.getByRole('button', { name: 'Save article' }).first().click();
    await expect(other.getByLabel('Published title').last()).toHaveText(
      'Published from another session',
    );
    await expect(
      other
        .getByRole('status')
        .filter({ hasText: 'New version available' })
        .first(),
    ).toBeVisible();
    await expect(draft).toHaveValue('Keep my unfinished draft');
    await other.getByRole('button', { name: 'Save article' }).first().click();
    await expect(page.getByLabel('Published title').first()).toHaveText(
      'Keep my unfinished draft',
    );
  } finally {
    await context.close();
  }
});

test('isolates private user topics and rejects forged and unauthorized subscription leases', async ({
  page,
  browser,
  appURL,
}) => {
  await connected(page, appURL);
  const root = page.locator('[data-interact-id]').first();
  const csrf = await root.getAttribute('data-interact-csrf');
  const id = await root.getAttribute('data-interact-id');
  const raw = await root.getAttribute('data-interact-realtime');
  if (!csrf || !id || !raw) throw new Error('Missing realtime metadata.');
  const metadata: unknown = JSON.parse(raw);
  if (!metadata || typeof metadata !== 'object' || !('lease' in metadata))
    throw new Error('Missing lease.');
  const missingCsrf = await page.request.post(`${appURL}/_interact/subscribe`, {
    data: { version: 1, leases: {} },
  });
  expect(missingCsrf.status()).toBe(403);
  const forged = await page.request.post(`${appURL}/_interact/subscribe`, {
    headers: { 'X-CSRF-Token': csrf },
    data: { version: 1, leases: { [id]: 'forged' } },
  });
  expect(await forged.json()).toMatchObject({
    grant: null,
    islands: { [id]: { error: 'Subscription authorization failed.' } },
  });
  const context = await browser.newContext();
  try {
    const other = await context.newPage();
    await connected(other, appURL);
    const otherCsrf = await other
      .locator('[data-interact-csrf]')
      .first()
      .getAttribute('data-interact-csrf');
    await other.request.post(`${appURL}/demo/realtime-user/2`, {
      headers: { 'X-CSRF-Token': otherCsrf ?? '' },
    });
    await connected(other, appURL);
    const grants: string[] = [];
    other.on('request', (request) => {
      if (request.url().includes('match=')) grants.push(request.url());
    });
    await page
      .getByRole('button', { name: 'Send private notification' })
      .first()
      .click();
    await expect(page.getByLabel('Private notification').first()).toHaveText(
      'An update just arrived.',
    );
    await expect(other.getByLabel('Private notification').first()).toHaveText(
      '',
    );
    await other
      .getByRole('button', { name: 'Send private notification' })
      .first()
      .click();
    await expect(other.getByLabel('Private notification').first()).toHaveText(
      'An update just arrived.',
    );
    expect(grants.every((url) => !url.includes('users%2F1'))).toBe(true);
  } finally {
    await context.close();
  }
  await page.request.post(`${appURL}/demo/role/viewer`, {
    headers: { 'X-CSRF-Token': csrf },
  });
  const denied = await page.request.post(`${appURL}/_interact/subscribe`, {
    headers: { 'X-CSRF-Token': csrf },
    data: { version: 1, leases: { [id]: metadata.lease } },
  });
  expect(await denied.json()).toMatchObject({
    grant: null,
    islands: { [id]: { error: 'Subscription authorization failed.' } },
  });
});

test('recovers the shared subscription after the independent hub restarts', async ({
  page,
  appURL,
  mercureHub,
}) => {
  if (!mercureHub) throw new Error('Missing real Mercure hub.');
  await connected(page, appURL);
  const reconciled = page.waitForResponse(
    (response) => response.url().endsWith('/_interact/update') && response.ok(),
  );
  const reconnected = page.waitForResponse(
    (response) =>
      response.request().method() === 'GET' &&
      response.url().startsWith(mercureHub.url),
  );
  await mercureHub.restart();
  await reconnected;
  await reconciled;
  await settled(page);
  await expect(
    page.getByText('connected', { exact: true }).first(),
  ).toBeVisible();
  await page
    .getByRole('button', { name: 'Send private notification' })
    .first()
    .click();
  await expect(page.getByLabel('Private notification').last()).toHaveText(
    'An update just arrived.',
  );
});

test('retains the inbox through publishing and initializes it through enhanced navigation on mobile', async ({
  page,
  appURL,
}, testInfo) => {
  const errors: string[] = [];
  page.on('pageerror', (error) => errors.push(error.message));
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto(appURL);
  await page.getByRole('button', { name: 'Menu', exact: true }).click();
  await page.getByRole('link', { name: 'Realtime', exact: true }).click();
  await settled(page);
  const notify = page.getByRole('button', {
    name: 'Send private notification',
  });
  await notify.click();
  await expect(page.getByLabel('Notifications received')).toHaveText('1');
  await page.getByLabel('Draft title').fill('Mobile publishing update');
  await page.getByRole('button', { name: 'Save article' }).click();
  await expect(page.getByLabel('Published title').last()).toHaveText(
    'Mobile publishing update',
  );
  await expect(page.getByLabel('Notifications received')).toHaveText('1');
  await expect(page.getByLabel('Private notification')).toHaveText(
    'An update just arrived.',
  );
  await notify.click();
  await expect(page.getByLabel('Notifications received')).toHaveText('2');
  const publish = page.getByRole('button', { name: 'Save article' });
  const before = await publish.boundingBox();
  await publish.click();
  await settled(page);
  const after = await publish.boundingBox();
  expect(before?.y).toBe(after?.y);
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= innerWidth,
    ),
  ).toBe(true);
  await page.evaluate(() => window.scrollTo(0, 0));
  await page.screenshot({
    path: testInfo.outputPath('realtime-mobile.png'),
    fullPage: true,
  });
  expect(errors).toEqual([]);
});
