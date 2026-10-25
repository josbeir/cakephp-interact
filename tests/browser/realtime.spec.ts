import type { Page } from '@playwright/test';
import { test, expect } from './fixtures';

test.use({ realtime: true });

async function connected(page: Page, appURL: string, path = '/realtime') {
  await page.goto(`${appURL}${path}`);
  await settled(page);
  await expect(
    page.getByText('connected', { exact: true }).first(),
  ).toBeVisible();
  await expect(
    page.getByRole('button', { name: 'Send message' }).first(),
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
  await expect(page.getByText('connected', { exact: true })).toHaveCount(1);
  expect(subscriptions).toHaveLength(1);
  expect(
    new URL(subscriptions[0] ?? '').searchParams.getAll('match').sort(),
  ).toEqual(['demo/chat', 'users/1/notifications', 'users/1/output']);
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
    page.locator('li').filter({ hasText: 'Conversation recap' }),
  ).toHaveCount(1);
  await expect(
    page.getByRole('progressbar', { name: 'Report progress' }),
  ).toHaveAttribute('aria-valuenow', '100');
  expect(subscriptions).toHaveLength(1);
  expect(errors).toEqual([]);
});

test('delivers chat messages immediately while another session is typing and restores saved history', async ({
  page,
  browser,
  appURL,
}) => {
  await connected(page, appURL);
  const context = await browser.newContext();
  try {
    const other = await context.newPage();
    await connected(other, appURL);
    const draft = other.getByLabel('Message', { exact: true });
    await draft.fill('Keep my unfinished message');
    await page.getByLabel('Your name').fill('Ada');
    await page
      .getByLabel('Message', { exact: true })
      .fill('Hello from another session <b>hello</b>');
    await page.getByLabel('Message', { exact: true }).press('Enter');
    const feed = other.getByRole('log', { name: 'Chat messages' });
    await expect(feed).toContainText('Hello from another session <b>hello</b>');
    await expect(feed.locator('b')).toHaveCount(0);
    await expect(draft).toHaveValue('Keep my unfinished message');
    await expect(page.getByLabel('Message', { exact: true })).toHaveValue('');
    await other.getByRole('button', { name: 'Send message' }).click();
    await expect(page.getByRole('log')).toContainText(
      'Keep my unfinished message',
    );
    await page.reload();
    await settled(page);
    await expect(page.getByRole('log')).toContainText(
      'Hello from another session <b>hello</b>',
    );
    await expect(page.getByRole('log')).toContainText(
      'Keep my unfinished message',
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

test('retains the inbox through sending and initializes it through enhanced navigation on mobile', async ({
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
  await page.getByLabel('Message', { exact: true }).fill('Hello from mobile');
  await page.getByRole('button', { name: 'Send message' }).click();
  await expect(page.getByRole('log')).toContainText('Hello from mobile');
  await expect(page.getByLabel('Notifications received')).toHaveText('1');
  await expect(page.getByLabel('Private notification')).toHaveText(
    'An update just arrived.',
  );
  await notify.click();
  await expect(page.getByLabel('Notifications received')).toHaveText('2');
  await page
    .getByLabel('Message', { exact: true })
    .fill('Another mobile hello');
  const publish = page.getByRole('button', { name: 'Send message' });
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

test('sends once and leaves action buttons enabled during background reconciliation', async ({
  page,
  appURL,
}) => {
  await connected(page, appURL);
  let release = () => {};
  const gate = new Promise<void>((resolve) => {
    release = resolve;
  });
  let sends = 0;
  let refreshes = 0;
  await page.route('**/_interact/update', async (route) => {
    const payload: unknown = route.request().postDataJSON();
    if (!payload || typeof payload !== 'object' || !('action' in payload))
      throw new Error('Missing island request action.');
    if (payload.action === 'send') sends++;
    if (payload.action === null) {
      refreshes++;
      await gate;
    }
    await route.continue();
  });
  try {
    await page
      .getByLabel('Message', { exact: true })
      .fill('One explicit message');
    await page.getByRole('button', { name: 'Send message' }).click();
    await expect.poll(() => refreshes).toBeGreaterThanOrEqual(1);
    for (const name of [
      'Send message',
      'Send private notification',
      'Generate report',
    ]) {
      await expect(page.getByRole('button', { name })).toBeEnabled();
    }
    expect(sends).toBe(1);
    release();
    await settled(page);
    expect(sends).toBe(1);
    expect(refreshes).toBeLessThanOrEqual(2);
  } finally {
    release();
    await page.unroute('**/_interact/update');
  }
});

test('retains custom scoped endpoints through initial refresh, actions, and hub delivery', async ({
  page,
  appURL,
}) => {
  const endpoints: string[] = [];
  page.on('request', (request) => {
    if (
      request.method() === 'POST' &&
      new URL(request.url()).pathname.endsWith('/subscribe')
    )
      endpoints.push(new URL(request.url()).pathname);
  });
  await connected(page, appURL, '/realtime/scoped');
  await page.getByRole('button', { name: 'Send private notification' }).click();
  await expect(page.getByLabel('Private notification')).toHaveText(
    'An update just arrived.',
  );
  await page
    .getByLabel('Message', { exact: true })
    .fill('Hello from the scoped chat');
  await page.getByRole('button', { name: 'Send message' }).click();
  await expect(page.getByRole('log')).toContainText(
    'Hello from the scoped chat',
  );
  await settled(page);
  await page.getByRole('button', { name: 'Send private notification' }).click();
  await expect(page.getByLabel('Notifications received')).toHaveText('2');
  expect(endpoints).toEqual(['/realtime/subscribe']);
  for (const root of await page.locator('[data-interact-realtime]').all()) {
    const raw = await root.getAttribute('data-interact-realtime');
    const metadata: unknown = JSON.parse(raw ?? '{}');
    expect(metadata).toMatchObject({ endpoint: '/realtime/subscribe' });
  }
});

test('sends and validates messages even when the browser cannot connect to the hub', async ({
  page,
  appURL,
  mercureHub,
}) => {
  if (!mercureHub) throw new Error('Missing real Mercure hub.');
  await page.route(`${mercureHub.url}**`, (route) => route.abort());
  await page.goto(`${appURL}/realtime`);
  const message = page.getByLabel('Message', { exact: true });
  const send = page.getByRole('button', { name: 'Send message' });
  await send.click();
  await expect(
    page.getByRole('alert').filter({ hasText: 'Write a message first.' }),
  ).toBeVisible();
  await message.fill('Local acknowledgement without a subscription');
  await send.click();
  await expect(page.getByRole('log')).toContainText(
    'Local acknowledgement without a subscription',
  );
  await expect(message).toHaveValue('');
  await expect(
    page.getByRole('alert').filter({ hasText: 'Write a message first.' }),
  ).toHaveCount(0);
});

test('keeps a newer message draft when an earlier send finishes', async ({
  page,
  appURL,
}) => {
  await connected(page, appURL);
  let release = () => {};
  const gate = new Promise<void>((resolve) => {
    release = resolve;
  });
  await page.route('**/_interact/update', async (route) => {
    const payload: unknown = route.request().postDataJSON();
    if (
      payload &&
      typeof payload === 'object' &&
      'action' in payload &&
      payload.action === 'send'
    ) {
      const response = await route.fetch();
      await gate;
      await route.fulfill({ response });
    } else await route.continue();
  });
  try {
    const message = page.getByLabel('Message', { exact: true });
    await message.fill('First message in flight');
    await page.getByRole('button', { name: 'Send message' }).click();
    await expect(
      page.getByRole('button', { name: 'Send message' }),
    ).toBeDisabled();
    await message.fill('My next message');
    release();
    await expect(page.getByRole('log')).toContainText(
      'First message in flight',
    );
    await expect(message).toHaveValue('My next message');
    await expect(
      page.getByRole('button', { name: 'Send message' }),
    ).toBeEnabled();
  } finally {
    release();
    await page.unroute('**/_interact/update');
  }
});
