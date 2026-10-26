import { expect, test } from './fixtures';

function selected(url: string, name: string): string | null {
  return new URL(url).searchParams.get(name);
}

test('direct links, normalization, reloads, and defaults use accepted PHP state', async ({
  page,
}) => {
  await page.goto('/search?q=keyboard&page=2&external=keep#results');
  const library = page.getByRole('region', { name: 'Resource library' });
  await expect(library.getByLabel('Search resources')).toHaveValue('keyboard');
  await expect(library.getByRole('status')).toHaveText(
    'Showing 21–30 of 30 results',
  );
  await page.reload();
  await expect(library.getByRole('status')).toHaveText(
    'Showing 21–30 of 30 results',
  );
  await page.goto('/search?q=keyboard&page=999&external=keep#results');
  await expect(library.getByRole('status')).toHaveText(
    'Showing 21–30 of 30 results',
  );
  await expect.poll(() => selected(page.url(), 'page')).toBe('2');
  await expect.poll(() => selected(page.url(), 'external')).toBe('keep');
  expect(new URL(page.url()).hash).toBe('#results');
  await page.goto('/search?q[unexpected]=bad&page=abc');
  await expect(library.getByRole('status')).toHaveText(
    'Showing 1–20 of 1,200 results',
  );
  await expect(page).toHaveURL('/search');
});

test('Back and Forward restore pagination and filters while preserving unrelated drafts', async ({
  page,
}) => {
  await page.goto('/search?q=keyboard');
  const library = page.getByRole('region', { name: 'Resource library' });
  const status = library.getByRole('status');
  await library.getByRole('link', { name: 'Next', exact: true }).click();
  await expect(status).toHaveText('Showing 21–30 of 30 results');
  await expect.poll(() => selected(page.url(), 'page')).toBe('2');
  await library.getByText('Reading notes', { exact: true }).first().click();
  await library.getByLabel('Reading notes').fill('Keep my unsent draft');
  await page.goBack();
  await expect(status).toHaveText('Showing 1–20 of 30 results');
  await expect(library.getByLabel('Reading notes')).toHaveValue(
    'Keep my unsent draft',
  );
  await expect(library.getByLabel('Search resources')).toHaveValue('keyboard');
  await page.goForward();
  await expect(status).toHaveText('Showing 21–30 of 30 results');
  await expect(library.getByLabel('Reading notes')).toHaveValue(
    'Keep my unsent draft',
  );
  await library.getByLabel('Search resources').fill('forms validation');
  await expect(status).toHaveText('Showing 1–15 of 15 results');
  await expect.poll(() => selected(page.url(), 'q')).toBe('forms validation');
  await page.goBack();
  await expect(library.getByLabel('Search resources')).toHaveValue('keyboard');
  await expect(status).toHaveText('Showing 21–30 of 30 results');
});

test('independent instances merge their owned parameters and retain stable unique IDs', async ({
  page,
}) => {
  const errors: string[] = [];
  page.on('pageerror', (error) => errors.push(error.message));
  await page.goto(
    '/search/independent?articles_q=keyboard&products_q=validation',
  );
  const listings = page.getByRole('region', { name: 'Resource library' });
  await expect(listings).toHaveCount(2);
  await expect(listings.nth(0).getByLabel('Search resources')).toHaveValue(
    'keyboard',
  );
  await expect(listings.nth(1).getByLabel('Search resources')).toHaveValue(
    'validation',
  );
  await listings
    .nth(0)
    .getByRole('link', { name: 'Next', exact: true })
    .click();
  await expect.poll(() => selected(page.url(), 'articles_page')).toBe('2');
  await listings.nth(1).getByLabel('Search resources').fill('CakePHP');
  await expect.poll(() => selected(page.url(), 'products_q')).toBe('CakePHP');
  expect(selected(page.url(), 'articles_q')).toBe('keyboard');
  expect(selected(page.url(), 'articles_page')).toBe('2');
  const ids = await page
    .locator('[id]')
    .evaluateAll((elements) => elements.map((element) => element.id));
  expect(new Set(ids).size).toBe(ids.length);
  expect(errors).toEqual([]);
});

test('an older response cannot rewrite the URL selected by Back', async ({
  page,
}) => {
  await page.goto('/search?q=keyboard');
  const library = page.getByRole('region', { name: 'Resource library' });
  await library.getByRole('link', { name: 'Next', exact: true }).click();
  await expect.poll(() => selected(page.url(), 'page')).toBe('2');
  let release: () => void = () => {};
  const held = new Promise<void>((resolve) => {
    release = resolve;
  });
  let intercepted = false;
  await page.route('**/_interact/update', async (route) => {
    if (!intercepted) {
      intercepted = true;
      await held;
    }
    await route.continue();
  });
  await library.getByLabel('Search resources').fill('CakePHP');
  await expect.poll(() => intercepted).toBe(true);
  await page.goBack();
  expect(selected(page.url(), 'q')).toBe('keyboard');
  release();
  await expect(library.getByRole('status')).toHaveText(
    'Showing 1–20 of 30 results',
  );
  await expect(library.getByLabel('Search resources')).toHaveValue('keyboard');
  await expect.poll(() => selected(page.url(), 'q')).toBe('keyboard');
  expect(selected(page.url(), 'page')).toBeNull();
});

test('invalid UTF-8 in direct links and history uses defaults without breaking the page', async ({
  page,
}) => {
  const errors: string[] = [];
  page.on('pageerror', (error) => errors.push(error.message));
  const response = await page.goto('/search?q=%FF&page=2');
  expect(response?.status()).toBe(200);
  const library = page.getByRole('region', { name: 'Resource library' });
  await expect(library.getByLabel('Search resources')).toHaveValue('');
  await expect(library.getByRole('status')).toHaveText(
    'Showing 21–40 of 1,200 results',
  );
  await expect(page).toHaveURL('/search?page=2');
  await page.evaluate(() => {
    history.replaceState(history.state, '', '/search?q=%FF&page=3');
    dispatchEvent(new PopStateEvent('popstate'));
  });
  await expect(library.getByRole('status')).toHaveText(
    'Showing 41–60 of 1,200 results',
  );
  await expect(library.getByLabel('Search resources')).toHaveValue('');
  await expect(page).toHaveURL('/search?page=3');
  expect(errors).toEqual([]);
});

test('malformed URL response state leaves the accepted render and draft intact', async ({
  page,
}) => {
  await page.goto('/search');
  const library = page.getByRole('region', { name: 'Resource library' });
  await page.route('**/_interact/update', async (route) => {
    const response = await route.fetch();
    const payload: unknown = await response.json();
    if (
      typeof payload !== 'object' ||
      payload === null ||
      Array.isArray(payload)
    ) {
      throw new Error('Expected an island response.');
    }
    await route.fulfill({
      response,
      json: { ...payload, state: { search: 'wrong', page: 'invalid' } },
    });
  });
  await library.getByLabel('Search resources').fill('keyboard');
  await expect(library.getByRole('alert')).toContainText(
    'The server returned an invalid update',
  );
  await expect(library.getByLabel('Search resources')).toHaveValue('keyboard');
  await expect(library.getByRole('status')).toHaveText(
    'Showing 1–20 of 1,200 results',
  );
  await expect(page).toHaveURL('/search');
});

test('nested filters and nullable scalars survive direct links, reloads and independent owners', async ({
  page,
}) => {
  const errors: string[] = [];
  page.on('pageerror', (error) => errors.push(error.message));
  await page.goto(
    '/demo/url-state?first_q[~null]=1&first_filters[price][min]=001&first_filters[tags][~empty]=1&second_q=other&external=keep#results',
  );
  const first = page.getByRole('region', { name: 'First filters' });
  const second = page.getByRole('region', { name: 'Second filters' });
  await expect(first.getByLabel('Query value')).toHaveText('null');
  await expect(first.getByLabel('Minimum price')).toHaveValue('001');
  await expect(first.getByLabel('Accepted filters')).toHaveText(
    '{"status":"","tags":[],"price":{"min":"001","max":"100"}}',
  );
  await expect(second.getByLabel('Query', { exact: true })).toHaveValue(
    'other',
  );
  await page.reload();
  await expect(first.getByLabel('Query value')).toHaveText('null');
  await expect(first.getByLabel('Minimum price')).toHaveValue('001');
  expect(selected(page.url(), 'external')).toBe('keep');
  expect(new URL(page.url()).hash).toBe('#results');
  expect(errors).toEqual([]);
});

test('form drafts update nested string values and renderless null stays distinct from empty text', async ({
  page,
}) => {
  await page.goto('/demo/url-state?external=one&external=two');
  const first = page.getByRole('region', { name: 'First filters' });
  await first.getByLabel('Maximum price').fill('250');
  await first.getByLabel('Maximum price').press('Tab');
  await expect
    .poll(() => selected(page.url(), 'first_filters[price][max]'))
    .toBe('250');
  await first.getByLabel('Query', { exact: true }).fill('');
  await first.getByLabel('Status', { exact: true }).fill('published');
  await first.getByLabel('Minimum price').fill('010');
  await first.getByRole('button', { name: 'Apply filters' }).click();
  await expect.poll(() => selected(page.url(), 'first_q')).toBe('');
  await expect
    .poll(() => selected(page.url(), 'first_filters[status]'))
    .toBe('published');
  expect(selected(page.url(), 'first_filters[price][min]')).toBe('010');
  await first.evaluate((element) =>
    element.setAttribute('data-renderless-check', 'kept'),
  );
  await first.getByRole('button', { name: 'Select null' }).click();
  await expect(first.getByLabel('Query value')).toHaveText('null');
  await expect(first).toHaveAttribute('data-renderless-check', 'kept');
  await expect.poll(() => selected(page.url(), 'first_q[~null]')).toBe('1');
  expect(selected(page.url(), 'first_q')).toBeNull();
  expect(new URL(page.url()).searchParams.getAll('external')).toEqual([
    'one',
    'two',
  ]);
  await page.reload();
  await expect(first.getByLabel('Query value')).toHaveText('null');
  await expect(first.getByLabel('Status', { exact: true })).toHaveValue(
    'published',
  );
  await expect(first.getByLabel('Minimum price')).toHaveValue('010');
});

test('structured history restores filters without replaying actions or losing unrelated drafts during navigation', async ({
  page,
}) => {
  await page.goto(
    '/demo/url-state?first_filters[status]=published&second_q=other',
  );
  const first = page.getByRole('region', { name: 'First filters' });
  await first.getByRole('button', { name: 'Clear tags' }).click();
  await expect
    .poll(() => selected(page.url(), 'first_filters[tags][~empty]'))
    .toBe('1');
  await first.getByLabel('Unsent notes').fill('Keep this newer draft');
  await page.goBack();
  await expect(first.getByLabel('Accepted filters')).toContainText(
    '"tags":["initial"]',
  );
  await expect(first.getByLabel('Action count')).toHaveText('1');
  await expect(first.getByLabel('Unsent notes')).toHaveValue(
    'Keep this newer draft',
  );
  await page.goForward();
  await expect(first.getByLabel('Accepted filters')).toContainText('"tags":[]');
  await expect(first.getByLabel('Action count')).toHaveText('1');
  await expect(first.getByLabel('Unsent notes')).toHaveValue(
    'Keep this newer draft',
  );
  const root = page.locator('[data-interact-id]').filter({ has: first });
  const identity = await root.getAttribute('data-interact-id');
  await page.getByRole('link', { name: 'Navigate with new query' }).click();
  await expect(first.getByLabel('Query value')).toHaveText('navigated');
  await expect(root).toHaveAttribute('data-interact-id', identity ?? 'missing');
  await expect(first.getByLabel('Unsent notes')).toHaveValue(
    'Keep this newer draft',
  );
  await expect(first.getByLabel('Action count')).toHaveText('1');
});

test('malformed nested bindings reset only their owning property before rendering', async ({
  page,
}) => {
  const response = await page.goto(
    '/demo/url-state?first_q=&first_filters[tags][4]=x&second_filters[status]=published',
  );
  expect(response?.status()).toBe(200);
  const first = page.getByRole('region', { name: 'First filters' });
  await expect(first.getByLabel('Query value')).toHaveText('');
  await expect(first.getByLabel('Accepted filters')).toHaveText(
    '{"status":"","tags":["initial"],"price":{"min":"","max":"100"}}',
  );
  await expect(
    page
      .getByRole('region', { name: 'Second filters' })
      .getByLabel('Status', { exact: true }),
  ).toHaveValue('published');
  await expect
    .poll(() => selected(page.url(), 'first_filters[tags][4]'))
    .toBeNull();
});
