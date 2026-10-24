import { test, expect } from './fixtures';

test('the public demo supports draft dumps, renderless updates, validation and failure recovery', async ({
  page,
}) => {
  await page.goto('/navigation');
  await page
    .getByRole('navigation', { name: 'Demo pages' })
    .getByRole('link', { name: 'Debugging', exact: true })
    .click();
  await expect(
    page.getByRole('heading', { name: 'Debugging', exact: true }),
  ).toBeVisible();
  await page.getByLabel('Debug message').fill('A user-editable draft');
  await page.getByRole('button', { name: 'Dump draft', exact: true }).click();
  const frame = page.frameLocator('iframe[title="Captured island output"]');
  await expect(frame.locator('pre')).toContainText('A user-editable draft');
  await expect(
    page.getByRole('status').filter({ hasText: 'JSON dump captured' }),
  ).toBeVisible();
  await page.getByRole('button', { name: 'Close island debug' }).click();
  await page.getByRole('button', { name: 'Dump without rendering' }).click();
  await expect(
    page
      .getByRole('status')
      .filter({ hasText: 'Dump captured without rendering' }),
  ).toBeVisible();
  await page.getByRole('button', { name: 'Close island debug' }).click();
  await page.getByLabel('Debug message').fill('');
  await page.getByRole('button', { name: 'Dump and validate' }).click();
  await expect(
    page
      .getByRole('alert')
      .filter({ hasText: 'Enter a message before validating.' }),
  ).toBeVisible();
  await expect(frame.locator('pre')).toContainText('before validation');
  await page.getByRole('button', { name: 'Close island debug' }).click();
  await page.getByLabel('Debug message').fill('Recovered draft');
  await page.getByRole('button', { name: 'Stream three dumps' }).click();
  await expect(
    page.getByRole('status').filter({ hasText: 'Streaming dumps captured' }),
  ).toBeVisible();
  await expect(page.getByLabel('Debug stream progress')).toContainText(
    'Step 3',
  );
  await expect(frame.locator('pre')).toContainText('Recovered draft');
  await page.getByRole('button', { name: 'Close island debug' }).click();
  await page.getByRole('button', { name: 'Dump then fail' }).click();
  await expect(frame.locator('pre')).toContainText(
    'before intentional failure',
  );
  await page.getByRole('button', { name: 'Close island debug' }).click();
  await page.getByRole('button', { name: 'Resume after failure' }).click();
  await expect(
    page.getByRole('button', { name: 'Resume after failure' }),
  ).toBeHidden();
  await expect(page.getByLabel('Debug message')).toHaveValue('Recovered draft');
});

test('native JSON dumps stay readable and inert while normal updates and focus work', async ({
  page,
}) => {
  const errors: string[] = [];
  const resources: string[] = [];
  page.on('pageerror', (error) => errors.push(error.message));
  page.on('request', (request) => {
    if (request.url().includes('debug-external')) resources.push(request.url());
  });
  await page.goto('/demo/debugging');
  const probe = page.getByRole('region', { name: 'Debug probe' }).first();
  const inspect = probe.getByRole('button', {
    name: 'Inspect island',
    exact: true,
  });
  await inspect.focus();
  await inspect.click();
  await expect(probe.locator('output')).toHaveText('Inspected');
  await expect(inspect).toBeFocused();
  const viewer = page.getByRole('complementary', { name: 'Island debug' });
  await expect(viewer).toBeVisible();
  const frame = page.frameLocator('iframe[title="Captured island output"]');
  await expect(frame.locator('pre')).toContainText('global dump');
  await expect(frame.locator('pre')).toContainText('********');
  await expect(frame.locator('script, img')).toHaveCount(0);
  await expect(
    page.locator('[data-interact-id] .cake-debug-output'),
  ).toHaveCount(0);
  await page.getByRole('button', { name: 'Close island debug' }).click();
  await expect(page.getByTitle('Captured island output')).toBeHidden();
  await page.getByRole('button', { name: 'Open island debug' }).click();
  await expect(page.getByTitle('Captured island output')).toBeVisible();
  await page.getByRole('button', { name: 'Clear island debug' }).click();
  await expect(viewer).toHaveCount(0);
  expect(resources).toEqual([]);
  expect(errors).toEqual([]);
});

test('renderless, validation and failed requests all expose dumps without masking their outcomes', async ({
  page,
}) => {
  await page.goto('/demo/debugging');
  const probe = page.getByRole('region', { name: 'Debug probe' }).first();
  await probe
    .getByRole('button', { name: 'Inspect without rendering' })
    .click();
  const frame = page.frameLocator('iframe[title="Captured island output"]');
  await expect(frame.locator('pre')).toContainText('global dump');
  await expect(probe.locator('output')).toHaveText('Initial');
  const validation = page.waitForResponse(
    (response) =>
      response.url().endsWith('/_interact/update') && response.status() === 422,
  );
  await probe.getByRole('button', { name: 'Inspect validation' }).click();
  await validation;
  await expect(probe.locator('output')).toHaveText('Inspected');
  const failure = page.waitForResponse(
    (response) =>
      response.url().endsWith('/_interact/update') && response.status() === 500,
  );
  await probe.getByRole('button', { name: 'Inspect failure' }).click();
  const body: unknown = await (await failure).json();
  expect(body).toMatchObject({
    diagnostics: { viewer: true },
    error: { code: 'server_error' },
  });
  await expect(frame.locator('pre')).toContainText('global dump');
});

test('SSE diagnostics accompany progress and final state without becoming public stream events', async ({
  page,
}) => {
  await page.goto('/demo/debugging');
  const observed = page.evaluate(
    () =>
      new Promise<string[]>((resolve) => {
        const messages: string[] = [];
        const receive = (event: Event) => {
          if (!(event instanceof CustomEvent)) return;
          const detail: unknown = event.detail;
          if (
            typeof detail === 'object' &&
            detail !== null &&
            'name' in detail &&
            typeof detail.name === 'string'
          )
            messages.push(detail.name);
          else messages.push('unexpected stream event');
        };
        window.addEventListener('interact:stream', receive);
        window.addEventListener(
          'interact:request-finished',
          () => {
            window.removeEventListener('interact:stream', receive);
            resolve(messages);
          },
          { once: true },
        );
      }),
  );
  const probe = page.getByRole('region', { name: 'Debug probe' }).first();
  const response = page.waitForResponse((response) =>
    response.url().endsWith('/_interact/update'),
  );
  await probe.getByRole('button', { name: 'Inspect stream' }).click();
  const stream = await response;
  expect(stream.headers()['content-type']).toContain('text/event-stream');
  expect(await observed).toEqual(['debug-progress']);
  await expect(probe.locator('output')).toHaveText('Inspected');
  await expect(
    page.frameLocator('iframe[title="Captured island output"]').locator('pre'),
  ).toContainText('before progress');
  await expect(
    page.getByRole('complementary', { name: 'Island debug' }),
  ).toHaveCount(1);
});

test('viewer opt-out keeps dumps in Network responses and creates no UI', async ({
  page,
}) => {
  await page.route('**/_interact/update', async (route) => {
    const response = await route.fetch();
    const payload: unknown = await response.json();
    if (
      typeof payload !== 'object' ||
      payload === null ||
      !('diagnostics' in payload) ||
      typeof payload.diagnostics !== 'object' ||
      payload.diagnostics === null
    )
      throw new Error('Missing server diagnostics');
    await route.fulfill({
      response,
      json: {
        ...payload,
        diagnostics: { ...payload.diagnostics, viewer: false },
      },
    });
  });
  await page.goto('/demo/debugging');
  const probe = page.getByRole('region', { name: 'Debug probe' }).first();
  await probe
    .getByRole('button', { name: 'Inspect island', exact: true })
    .click();
  await expect(probe.locator('output')).toHaveText('Inspected');
  await expect(
    page.getByRole('complementary', { name: 'Island debug' }),
  ).toHaveCount(0);
});

test('the viewer survives island morphs, replaces other islands output and clears on navigation', async ({
  page,
}) => {
  await page.goto('/demo/debugging');
  const probes = page.getByRole('region', { name: 'Debug probe' });
  const first = probes.first();
  await first
    .getByRole('button', { name: 'Inspect island', exact: true })
    .click();
  const viewer = page.getByRole('complementary', { name: 'Island debug' });
  await expect(viewer).toBeVisible();
  const second = probes.nth(1);
  await second.getByLabel('Probe value').fill('Other island');
  await second
    .getByRole('button', { name: 'Inspect island', exact: true })
    .click();
  const frame = page.frameLocator('iframe[title="Captured island output"]');
  await expect(frame.locator('pre')).toContainText('Other island');
  await expect(viewer).toHaveCount(1);
  await page.getByRole('link', { name: 'Leave debug probe' }).click();
  await expect(page).toHaveURL(/\/navigation$/);
  await expect(viewer).toHaveCount(0);
});

test('the viewer remains usable on a narrow screen with a strict style and script CSP', async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.route('**/demo/debugging', async (route) => {
    const response = await route.fetch();
    await route.fulfill({
      response,
      headers: {
        ...response.headers(),
        'content-security-policy':
          "default-src 'self'; script-src 'self'; style-src 'self'; object-src 'none'; base-uri 'self'",
      },
    });
  });
  await page.goto('/demo/debugging');
  await page
    .getByRole('button', { name: 'Inspect island', exact: true })
    .first()
    .click();
  const viewer = page.getByRole('complementary', { name: 'Island debug' });
  await expect(viewer).toBeVisible();
  await expect(
    page.frameLocator('iframe[title="Captured island output"]').locator('pre'),
  ).toContainText('global dump');
  const bounds = await viewer.boundingBox();
  expect(bounds).not.toBeNull();
  if (bounds) {
    expect(bounds.x).toBeGreaterThanOrEqual(0);
    expect(bounds.x + bounds.width).toBeLessThanOrEqual(390);
  }
});
