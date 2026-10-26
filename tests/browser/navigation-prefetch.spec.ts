import type { Locator, Page } from '@playwright/test';
import { expect, test } from './fixtures';

test.afterEach(async ({ page }) => {
  await page.unrouteAll({ behavior: 'wait' });
});

/** Render one real navigation link outside x-data using the existing demo document. */
async function prefetchLink(page: Page, suffix = ''): Promise<Locator> {
  await page.route('**/navigation', async (route) => {
    const response = await route.fetch();
    const body = (await response.text()).replace(
      '<main id="main" class="page-shell">',
      `<main id="main" class="page-shell">
        <nav aria-label="Prefetch test">
          <a href="/navigation/details" x-interact:navigate${suffix}>Prefetch details</a>
        </nav>`,
    );
    await route.fulfill({ response, body });
  });
  await page.goto('/navigation');
  return page
    .getByRole('navigation', { name: 'Prefetch test' })
    .getByRole('link');
}

for (const surface of ['Composer', 'application-owned Alpine']) {
  for (const activation of ['mouse', 'keyboard']) {
    test(`${surface}: completed hover prefetch serves ${activation} activation with one GET`, async ({
      page,
    }) => {
      if (surface === 'application-owned Alpine') {
        await page.route('**/interact/js/runtime.js', (route) =>
          route.fulfill({
            path: 'tests/test_app/webroot/js/configuration.js',
            contentType: 'text/javascript',
          }),
        );
      }
      const link = await prefetchLink(page, '.hover');
      const notebook = page.locator(
        '[x-interact\\:persist="notebook"] [data-interact-id]',
      );
      const identity = await notebook.getAttribute('data-interact-id');
      let requests = 0;
      await page.route('**/navigation/details', async (route) => {
        requests++;
        const response = await route.fetch();
        await route.fulfill({
          response,
          headers: { ...response.headers(), 'cache-control': 'no-store' },
        });
      });
      await page.clock.install();
      const fetched = page.waitForResponse(
        (response) =>
          new URL(response.url()).pathname === '/navigation/details',
      );
      if (activation === 'mouse') await link.hover();
      else await link.focus();
      await page.clock.runFor(60);
      expect(await (await fetched).finished()).toBeNull();
      await page.clock.runFor(100);
      await expect(page).toHaveURL('/navigation');
      expect(requests).toBe(1);
      await page.clock.resume();
      if (activation === 'mouse') await link.click();
      else await link.press('Enter');
      await expect(
        page.getByRole('heading', { name: 'Details', exact: true }),
      ).toBeVisible();
      await expect(notebook).toHaveAttribute(
        'data-interact-id',
        identity ?? 'missing',
      );
      expect(requests).toBe(1);
    });
  }

  for (const policy of ['no-store', 'no-cache', '']) {
    test(`${surface}: a completed ${policy || 'unversioned'} mouse-down response serves the same click once`, async ({
      page,
    }) => {
      if (surface === 'application-owned Alpine') {
        await page.route('**/interact/js/runtime.js', (route) =>
          route.fulfill({
            path: 'tests/test_app/webroot/js/configuration.js',
            contentType: 'text/javascript',
          }),
        );
      }
      const link = await prefetchLink(page);
      const notebook = page.locator(
        '[x-interact\\:persist="notebook"] [data-interact-id]',
      );
      const identity = await notebook.getAttribute('data-interact-id');
      let requests = 0;
      await page.route('**/navigation/details', async (route) => {
        requests++;
        const response = await route.fetch();
        await route.fulfill({
          response,
          headers: { ...response.headers(), 'cache-control': policy },
        });
      });
      await page.clock.install();
      await link.hover();
      const fetched = page.waitForResponse(
        (response) =>
          new URL(response.url()).pathname === '/navigation/details',
      );
      await page.mouse.down();
      expect(await (await fetched).finished()).toBeNull();
      // Flush response handling before releasing the button; no real-time sleep.
      await page.clock.runFor(100);
      await expect(page).toHaveURL('/navigation');
      await expect(
        page.getByRole('heading', { name: 'Overview', exact: true }),
      ).toBeVisible();
      expect(requests).toBe(1);
      await page.clock.resume();
      await page.mouse.up();
      await expect(
        page.getByRole('heading', { name: 'Details', exact: true }),
      ).toBeVisible();
      await expect(notebook).toHaveAttribute(
        'data-interact-id',
        identity ?? 'missing',
      );
      expect(requests).toBe(1);
      // A later activation must fetch afresh, even though the URL is the same.
      await page
        .getByRole('navigation', { name: 'Navigation example' })
        .getByRole('link', { name: 'Details', exact: true })
        .focus();
      await page
        .getByRole('navigation', { name: 'Navigation example' })
        .getByRole('link', { name: 'Details', exact: true })
        .press('Enter');
      await expect.poll(() => requests).toBe(2);
      await expect(
        page.getByRole('heading', { name: 'Details', exact: true }),
      ).toBeVisible();
    });
  }

  test(`${surface}: mouse-down starts one inert request that click joins`, async ({
    page,
  }) => {
    if (surface === 'application-owned Alpine') {
      await page.route('**/interact/js/runtime.js', (route) =>
        route.fulfill({
          path: 'tests/test_app/webroot/js/configuration.js',
          contentType: 'text/javascript',
        }),
      );
    }
    const errors: string[] = [];
    page.on('pageerror', (error) => errors.push(error.message));
    const link = await prefetchLink(page);
    const notebook = page.locator(
      '[x-interact\\:persist="notebook"] [data-interact-id]',
    );
    const identity = await notebook.getAttribute('data-interact-id');
    let start: (() => void) | undefined;
    let release: (() => void) | undefined;
    const started = new Promise<void>((resolve) => {
      start = resolve;
    });
    const held = new Promise<void>((resolve) => {
      release = resolve;
    });
    let requests = 0;
    await page.route('**/navigation/details', async (route) => {
      requests++;
      start?.();
      const response = await route.fetch();
      await held;
      await route.fulfill({
        response,
        headers: { ...response.headers(), 'cache-control': 'no-store' },
      });
    });
    await link.hover();
    await page.mouse.down();
    try {
      await started;
      await expect(page).toHaveURL('/navigation');
      await expect(
        page.getByRole('heading', { name: 'Overview', exact: true }),
      ).toBeVisible();
      expect(requests).toBe(1);
      await page.mouse.up();
    } finally {
      release?.();
    }
    await expect(
      page.getByRole('heading', { name: 'Details', exact: true }),
    ).toBeVisible();
    await expect(notebook).toHaveAttribute(
      'data-interact-id',
      identity ?? 'missing',
    );
    expect(requests).toBe(1);
    expect(errors).toEqual([]);
  });
}

for (const policy of ['private, max-age=30', 'no-store']) {
  test(`a cancelled press honors ${policy} before keyboard activation`, async ({
    page,
  }) => {
    const link = await prefetchLink(page);
    let requests = 0;
    await page.route('**/navigation/details', async (route) => {
      requests++;
      const response = await route.fetch();
      await route.fulfill({
        response,
        headers: { ...response.headers(), 'cache-control': policy },
      });
    });
    await link.hover();
    const fetched = page.waitForResponse(
      (response) => new URL(response.url()).pathname === '/navigation/details',
    );
    await page.mouse.down();
    const response = await fetched;
    expect(await response.finished()).toBeNull();
    await page.mouse.move(1, 1);
    await page.mouse.up();
    await expect(page).toHaveURL('/navigation');
    expect(requests).toBe(1);
    await link.focus();
    await link.press('Enter');
    await expect(
      page.getByRole('heading', { name: 'Details', exact: true }),
    ).toBeVisible();
    expect(requests).toBe(policy === 'no-store' ? 2 : 1);
  });
}

for (const suffix of [
  '.no-prefetch',
  '.hover.no-prefetch.trail.preserve-scroll',
  '.preserve-scroll.no-prefetch.hover.trail',
]) {
  test(`${suffix} disables speculative requests and keeps enhanced visits`, async ({
    page,
  }) => {
    const link = await prefetchLink(page, suffix);
    const notebook = page.locator(
      '[x-interact\\:persist="notebook"] [data-interact-id]',
    );
    const identity = await notebook.getAttribute('data-interact-id');
    let requests = 0;
    page.on('request', (request) => {
      if (new URL(request.url()).pathname === '/navigation/details') requests++;
    });
    await page.clock.install();
    await link.focus();
    await link.hover();
    await page.mouse.down();
    await page.clock.runFor(100);
    expect(requests).toBe(0);
    await page.clock.resume();
    await page.mouse.up();
    await expect(
      page.getByRole('heading', { name: 'Details', exact: true }),
    ).toBeVisible();
    await expect(notebook).toHaveAttribute(
      'data-interact-id',
      identity ?? 'missing',
    );
    expect(requests).toBe(1);
  });
}
