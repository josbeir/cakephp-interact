import { expect, test } from '../fixtures';
import { islandId, targetsIsland } from '../support/requests';

test('application-owned Alpine applies timing defaults under CSP and retains the current draft', async ({
  page,
}) => {
  await page.clock.install({ time: new Date('2026-10-23T12:00:00Z') });
  await page.clock.pauseAt(new Date('2026-10-23T12:00:01Z'));
  await page.route('**/interact/js/runtime.js', (route) =>
    route.fulfill({
      path: 'tests/test_app/webroot/js/configuration.js',
      contentType: 'text/javascript',
    }),
  );
  const errors: string[] = [];
  page.on('pageerror', (error) => errors.push(error.message));
  let owner = '';
  let requests = 0;
  let release = () => {};
  let seen = () => {};
  const held = new Promise<void>((resolve) => {
    release = resolve;
  });
  const started = new Promise<void>((resolve) => {
    seen = resolve;
  });
  await page.route('**/_interact/update', async (route) => {
    if (targetsIsland(route.request(), owner)) {
      requests++;
      seen();
      await held;
    }
    await route.continue();
  });
  await page.goto('/');
  const articles = page.getByRole('region', { name: 'Articles', exact: true });
  owner = await islandId(articles);
  const search = articles.getByLabel('Search articles');
  await search.fill('CakePHP');
  await page.clock.runFor(399);
  expect(requests).toBe(0);
  await page.clock.runFor(1);
  await started;
  await expect(articles.getByText('Updating…', { exact: true })).toBeHidden();
  await page.clock.runFor(299);
  await expect(articles.getByText('Updating…', { exact: true })).toBeHidden();
  await page.clock.runFor(51);
  await expect(articles.getByText('Updating…', { exact: true })).toBeVisible();
  await page.clock.resume();
  release();
  await expect(articles.getByText('Updating…', { exact: true })).toBeHidden();
  await expect(search).toHaveValue('CakePHP');
  await expect(search).toBeFocused();
  expect(requests).toBe(1);
  expect(errors).toEqual([]);
});

test('explicit model debounce overrides the application installation default', async ({
  page,
}) => {
  await page.clock.install({ time: new Date('2026-10-23T12:00:00Z') });
  await page.clock.pauseAt(new Date('2026-10-23T12:00:01Z'));
  await page.route('**/interact/js/runtime.js', (route) =>
    route.fulfill({
      path: 'tests/test_app/webroot/js/configuration.js',
      contentType: 'text/javascript',
    }),
  );
  let requests = 0;
  await page.route('**/_interact/update', async (route) => {
    requests++;
    await route.continue();
  });
  await page.goto('/search');
  await page.getByLabel('Search resources').fill('CakePHP');
  await page.clock.runFor(299);
  expect(requests).toBe(0);
  await page.clock.runFor(1);
  await expect.poll(() => requests).toBe(1);
});
