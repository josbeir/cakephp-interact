import { test, expect } from './fixtures';

test.afterEach(async ({ page }) => {
  await page.unrouteAll({ behavior: 'wait' });
});

test('removes page CSS, retains explicit styles and runtime nodes, and restores the cascade through history', async ({
  page,
}) => {
  const errors: string[] = [];
  page.on('pageerror', (error) => errors.push(error.message));
  await page.route('**/navigation**', async (route) => {
    const response = await route.fetch();
    await route.fulfill({
      response,
      body: (await response.text()).replace(
        '<html lang="en">',
        '<html lang="en" x-data>',
      ),
    });
  });
  await page.goto('/navigation');
  const shared = await page
    .locator('#navigation-shared-accent')
    .elementHandle();
  const common = await page.locator('link[href="/demo.css"]').elementHandle();
  if (!shared || !common) throw new Error('Missing shared head fixtures.');
  await page.evaluate(() => {
    const style = document.createElement('style');
    style.id = 'runtime-style';
    style.textContent = '.runtime-widget { color: green; }';
    document.head.append(style);
  });
  const tabs = page.getByRole('navigation', { name: 'Navigation example' });
  const badge = page.getByRole('region', { name: 'Page styles' });
  await expect(badge).toHaveCSS(
    'border-inline-start-color',
    'rgb(167, 139, 250)',
  );
  await tabs.getByRole('link', { name: 'Details', exact: true }).click();
  await expect(badge).toHaveCSS(
    'border-inline-start-color',
    'rgb(103, 232, 249)',
  );
  expect(errors).toEqual([]);
  await expect(
    page.locator('link[href*="navigation-overview.css"]'),
  ).toHaveCount(0);
  expect(await shared.evaluate((style) => style.isConnected)).toBe(true);
  expect(await common.evaluate((link) => link.isConnected)).toBe(true);
  await expect(page.locator('#runtime-style')).toHaveCount(1);
  await page.goBack();
  await expect(badge).toHaveCSS(
    'border-inline-start-color',
    'rgb(167, 139, 250)',
  );
  await expect(
    page.locator('link[href*="navigation-details.css"]'),
  ).toHaveCount(0);
  await page.goForward();
  await expect(badge).toHaveCSS(
    'border-inline-start-color',
    'rgb(103, 232, 249)',
  );
});

test('maps rotating CSP nonces for styles, new external scripts, prefetched pages, and history without policy violations', async ({
  page,
}) => {
  const policies: string[] = [];
  await page.route('**/navigation/nonces**', async (route) => {
    const response = await route.fetch();
    const policy = response.headers()['content-security-policy'] ?? '';
    policies.push(policy);
    const nonce = policy.match(/script-src 'nonce-([^']+)'/)?.[1];
    if (!nonce) throw new Error('Missing script nonce.');
    const body = (await response.text()).replace(
      '</head>',
      `${route.request().url().includes('/details') ? `<script src="/nonce-asset.js" nonce="${nonce}"></script>` : ''}</head>`,
    );
    await route.fulfill({
      response,
      body,
      headers: {
        ...response.headers(),
        'cache-control': 'private, max-age=30',
      },
    });
  });
  await page.route('**/nonce-asset.js', (route) =>
    route.fulfill({
      contentType: 'application/javascript',
      body: 'const probe = document.createElement("meta"); probe.name = "nonce-asset-executed"; document.head.append(probe);',
    }),
  );
  const errors: string[] = [];
  page.on('pageerror', (error) => errors.push(error.message));
  await page.goto('/navigation/nonces');
  const initial = await page
    .locator('meta[name="interact-navigation-nonce"]')
    .evaluateAll((markers) =>
      markers.map((marker) =>
        marker instanceof HTMLMetaElement ? marker.nonce : '',
      ),
    );
  await page.evaluate(() => {
    const violations = document.createElement('meta');
    violations.id = 'policy-violations';
    violations.content = '';
    document.head.append(violations);
    document.addEventListener('securitypolicyviolation', (event) => {
      violations.content += event.violatedDirective + ';';
    });
  });
  const tabs = page.getByRole('navigation', { name: 'Nonce example' });
  const details = tabs.getByRole('link', {
    name: 'Nonce details',
    exact: true,
  });
  const prefetched = page.waitForResponse((response) =>
    response.url().includes('/navigation/nonces/details'),
  );
  await details.dispatchEvent('mousedown', { button: 0 });
  await prefetched;
  await details.click();
  await expect(
    page.getByRole('heading', { name: 'Nonce details', exact: true }),
  ).toBeVisible();
  await expect(page.locator('.nonce-example')).toHaveCSS(
    'color',
    'rgb(103, 232, 249)',
  );
  await expect(page.locator('meta[name="nonce-asset-executed"]')).toHaveCount(
    1,
  );
  expect(
    await page
      .locator('meta[name="interact-navigation-nonce"]')
      .evaluateAll((markers) =>
        markers.map((marker) =>
          marker instanceof HTMLMetaElement ? marker.nonce : '',
        ),
      ),
  ).toEqual(initial);
  await page.goBack();
  await expect(page.locator('.nonce-example')).toHaveCSS(
    'color',
    'rgb(167, 139, 250)',
  );
  await page.goForward();
  await expect(page.locator('.nonce-example')).toHaveCSS(
    'color',
    'rgb(103, 232, 249)',
  );
  await expect(page.locator('meta[name="nonce-asset-executed"]')).toHaveCount(
    1,
  );
  await expect(page.locator('#policy-violations')).toHaveAttribute(
    'content',
    '',
  );
  expect(new Set(policies).size).toBeGreaterThanOrEqual(3);
  expect(errors).toEqual([]);
});

test('reloads tracked hashed bundles before executing destination code in the old document', async ({
  page,
}) => {
  await page.route('**/tracked.*.js', (route) =>
    route.fulfill({
      contentType: 'application/javascript',
      body: `document.documentElement.setAttribute('data-tracked-version', '${route.request().url().includes('.v2.') ? 'v2' : 'v1'}');`,
    }),
  );
  await page.route('**/navigation**', async (route) => {
    const response = await route.fetch();
    const version = route.request().url().includes('/details') ? 'v2' : 'v1';
    await route.fulfill({
      response,
      body: (await response.text()).replace(
        '</head>',
        `<script src="/tracked.${version}.js" x-interact:asset="reload"></script></head>`,
      ),
    });
  });
  await page.goto('/navigation');
  await expect(page.locator('html')).toHaveAttribute(
    'data-tracked-version',
    'v1',
  );
  await page.evaluate(() => {
    const old = document.createElement('meta');
    old.id = 'old-document';
    document.head.append(old);
  });
  const requestKinds: string[] = [];
  page.on('request', (request) => {
    if (request.url().includes('/navigation/details'))
      requestKinds.push(request.resourceType());
  });
  await page
    .getByRole('navigation', { name: 'Navigation example' })
    .getByRole('link', { name: 'Details', exact: true })
    .click();
  await expect(page.locator('html')).toHaveAttribute(
    'data-tracked-version',
    'v2',
  );
  await expect(page.locator('#old-document')).toHaveCount(0);
  expect(requestKinds).toContain('document');
});

test('requires native navigation for an actual policy change even when both policies rotate nonces', async ({
  page,
}) => {
  await page.goto('/navigation/nonces');
  await page.evaluate(() => {
    const marker = document.createElement('meta');
    marker.id = 'original-nonce-document';
    document.head.append(marker);
  });
  const kinds: string[] = [];
  await page.route('**/navigation/nonces/details', async (route) => {
    kinds.push(route.request().resourceType());
    const response = await route.fetch();
    const headers = response.headers();
    // Model a final application policy change, including its middleware fingerprint.
    const body = (await response.text()).replace(
      /(&quot;policy&quot;:&quot;)[a-f0-9]+/,
      '$1changed-policy',
    );
    await route.fulfill({
      response,
      body,
      headers: {
        ...headers,
        'content-security-policy':
          headers['content-security-policy'] + "; frame-src 'none'",
      },
    });
  });
  await page
    .getByRole('navigation', { name: 'Nonce example' })
    .getByRole('link', { name: 'Nonce details', exact: true })
    .click();
  await expect(
    page.getByRole('heading', { name: 'Nonce details', exact: true }),
  ).toBeVisible();
  await expect(page.locator('#original-nonce-document')).toHaveCount(0);
  expect(kinds).toContain('document');
});

test('does not authorize unknown, cross-capability, or pre-stamped active style nonces', async ({
  page,
}) => {
  await page.goto('/navigation/nonces');
  const activeStyle = await page
    .locator('style[nonce]')
    .evaluate((style) =>
      style instanceof HTMLStyleElement ? style.nonce : '',
    );
  await page.route('**/navigation/nonces/details', async (route) => {
    const response = await route.fetch();
    const headers = response.headers();
    const scriptNonce = headers['content-security-policy']?.match(
      /script-src 'nonce-([^']+)'/,
    )?.[1];
    await route.fulfill({
      response,
      body: (await response.text())
        .replace(
          '</head>',
          `<style nonce="unknown">#unknown-probe{display:none}</style><style nonce="${activeStyle}">#active-probe{display:none}</style><style nonce="${scriptNonce}">#cross-probe{display:none}</style></head>`,
        )
        .replace(
          '</main>',
          '<p id="unknown-probe">Unknown nonce</p><p id="active-probe">Pre-stamped nonce</p><p id="cross-probe">Script nonce on a style</p></main>',
        ),
    });
  });
  await page
    .getByRole('navigation', { name: 'Nonce example' })
    .getByRole('link', { name: 'Nonce details', exact: true })
    .click();
  await expect(
    page.getByRole('heading', { name: 'Nonce details', exact: true }),
  ).toBeVisible();
  await expect(page.getByText('Unknown nonce', { exact: true })).toBeVisible();
  await expect(
    page.getByText('Pre-stamped nonce', { exact: true }),
  ).toBeVisible();
  await expect(
    page.getByText('Script nonce on a style', { exact: true }),
  ).toBeVisible();
  await expect(page.locator('.nonce-example')).toHaveCSS(
    'color',
    'rgb(103, 232, 249)',
  );
});
