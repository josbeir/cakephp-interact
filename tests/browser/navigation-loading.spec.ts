import type { Locator, Page, Request } from '@playwright/test';
import { expect, test } from './fixtures';
import { targetsIsland } from './requests';

function report(page: Page, name: string): Locator {
  return page
    .getByRole('region', { name, exact: true })
    .locator('[data-interact-id]')
    .first();
}

async function identity(root: Locator): Promise<string> {
  const id = await root.getAttribute('data-interact-id');
  if (!id) throw new Error('Missing report identity.');
  return id;
}

/** Keep changes above the lazy report controlled while testing its final viewport. */
async function holdDeferredReport(page: Page): Promise<() => void> {
  let release = () => {};
  const gate = new Promise<void>((resolve) => {
    release = resolve;
  });
  await page.route('**/_interact/update', async (route) => {
    const deferred = report(page, 'Deferred report');
    if (
      (await deferred.count()) &&
      targetsIsland(route.request(), await identity(deferred))
    )
      await gate;
    await route.continue();
  });
  return release;
}

async function scrolledPlayground(page: Page): Promise<void> {
  await page.setViewportSize({ width: 1280, height: 720 });
  await page.goto('/playground');
  await page.evaluate(() => {
    window.Alpine?.store('loadingNavigationProof', { source: true });
    window.scrollTo(0, 1200);
  });
  await expect.poll(() => page.evaluate(() => window.scrollY)).toBe(1200);
}

test('a scrolled enhanced visit keeps below-fold and closed-panel lazy islands pending', async ({
  page,
}) => {
  await scrolledPlayground(page);
  const requests: Request[] = [];
  page.on('request', (request) => {
    if (request.method() === 'POST') requests.push(request);
  });
  await page
    .getByRole('navigation', { name: 'Demo pages' })
    .getByRole('link', { name: 'Lazy loading', exact: true })
    .click();
  await expect(page).toHaveURL('/loading');
  await expect(
    page.getByRole('region', { name: 'Deferred resource report', exact: true }),
  ).toBeVisible();
  expect(
    await page.evaluate(() => window.Alpine?.store('loadingNavigationProof')),
  ).toEqual({ source: true });
  await expect.poll(() => page.evaluate(() => window.scrollY)).toBe(0);

  const lazy = report(page, 'Lazy report');
  const hidden = report(page, 'Hidden report');
  const lazyId = await identity(lazy);
  const hiddenId = await identity(hidden);
  await expect(lazy).not.toBeInViewport();
  await expect(lazy).toHaveAttribute('data-interact-loading', 'lazy');
  await expect(hidden).toHaveAttribute('data-interact-loading', 'lazy');
  await expect(report(page, 'Manual report')).toHaveAttribute(
    'data-interact-loading',
    'manual',
  );
  expect(
    requests.filter((request) => targetsIsland(request, lazyId)),
  ).toHaveLength(0);
  expect(
    requests.filter((request) => targetsIsland(request, hiddenId)),
  ).toHaveLength(0);

  await lazy.scrollIntoViewIfNeeded();
  await expect(lazy).not.toHaveAttribute('data-interact-loading');
  expect(
    requests.filter((request) => targetsIsland(request, lazyId)),
  ).toHaveLength(1);
  await page.getByRole('button', { name: 'Toggle report panel' }).click();
  await hidden.scrollIntoViewIfNeeded();
  await expect(hidden).not.toHaveAttribute('data-interact-loading');
  expect(
    requests.filter((request) => targetsIsland(request, hiddenId)),
  ).toHaveLength(1);
});

for (const mode of ['fragment', 'preserved scroll'] as const) {
  test(`lazy islands load in the final viewport after enhanced ${mode} positioning`, async ({
    page,
  }) => {
    await scrolledPlayground(page);
    const release = await holdDeferredReport(page);
    try {
      const link = page
        .getByRole('navigation', { name: 'Demo pages' })
        .getByRole('link', { name: 'Lazy loading', exact: true });
      await link.evaluate((element, positioning) => {
        const replacement = element.cloneNode(true);
        if (!(replacement instanceof HTMLAnchorElement))
          throw new Error('Expected a navigation link.');
        replacement.removeAttribute('x-interact:navigate');
        replacement.setAttribute(
          positioning === 'preserved scroll'
            ? 'x-interact:navigate.preserve-scroll'
            : 'x-interact:navigate',
          '',
        );
        replacement.href =
          positioning === 'fragment' ? '/loading#visible-report' : '/loading';
        element.replaceWith(replacement);
      }, mode);
      await link.click();
      const lazy = report(page, 'Lazy report');
      await expect(lazy).not.toHaveAttribute('data-interact-loading');
      await expect(
        page.getByRole('region', {
          name: 'Visible resource report',
          exact: true,
        }),
      ).toBeVisible();
      expect(
        await page.evaluate(() =>
          window.Alpine?.store('loadingNavigationProof'),
        ),
      ).toEqual({ source: true });
      if (mode === 'preserved scroll')
        await expect.poll(() => page.evaluate(() => window.scrollY)).toBe(1200);
      else await expect(page).toHaveURL('/loading#visible-report');
      await expect(report(page, 'Hidden report')).toHaveAttribute(
        'data-interact-loading',
        'lazy',
      );
      await expect(report(page, 'Manual report')).toHaveAttribute(
        'data-interact-loading',
        'manual',
      );
    } finally {
      release();
    }
  });
}

test('history restoration keeps off-screen lazy reports pending and gives them fresh ownership', async ({
  page,
}) => {
  await page.goto('/loading');
  await expect(
    page.getByRole('region', { name: 'Deferred resource report', exact: true }),
  ).toBeVisible();
  const previous = await identity(report(page, 'Lazy report'));
  await page
    .getByRole('navigation', { name: 'Demo pages' })
    .getByRole('link', { name: 'Navigation', exact: true })
    .click();
  await expect(page).toHaveURL('/navigation');
  await page.goBack();
  await expect(page).toHaveURL('/loading');
  await expect(
    page.getByRole('region', { name: 'Deferred resource report', exact: true }),
  ).toBeVisible();
  const lazy = report(page, 'Lazy report');
  expect(await identity(lazy)).not.toBe(previous);
  await expect.poll(() => page.evaluate(() => window.scrollY)).toBe(0);
  await expect(lazy).not.toBeInViewport();
  await expect(lazy).toHaveAttribute('data-interact-loading', 'lazy');
  await lazy.scrollIntoViewIfNeeded();
  await expect(lazy).not.toHaveAttribute('data-interact-loading');
});
