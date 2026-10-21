import { expect, test } from '@playwright/test';

test('searches the full resource library and combines filters while preserving focus', async ({
  page,
}) => {
  await page.goto('/search');
  const library = page.getByRole('region', { name: 'Resource library' });
  const results = library.getByRole('status');
  const rows = library.locator('tbody tr');
  const search = library.getByLabel('Search resources');
  await expect(results).toHaveText('Showing 1–20 of 1,200 results');
  await expect(rows).toHaveCount(20);
  await search.fill('CakePHP pagination');
  await expect(results).toHaveText('Showing 1–15 of 15 results');
  await expect(rows).toHaveCount(15);
  await expect(search).toBeFocused();
  await expect(search).toHaveValue('CakePHP pagination');
  await library
    .getByRole('combobox', { name: 'Difficulty', exact: true })
    .selectOption('beginner');
  await expect(results).toHaveText('Showing 1–5 of 5 results');
  await library
    .getByRole('combobox', { name: 'Topic', exact: true })
    .selectOption('forms');
  await expect(
    library.getByRole('heading', { name: 'No resources found' }),
  ).toBeVisible();
  await library.getByRole('button', { name: 'Show all resources' }).click();
  await expect(results).toHaveText('Showing 1–20 of 1,200 results');
  await expect(search).toHaveValue('');
});

test('paginates, changes page size, and sorts the server results', async ({
  page,
}) => {
  await page.goto('/search');
  const library = page.getByRole('region', { name: 'Resource library' });
  const results = library.getByRole('status');
  const rows = library.locator('tbody tr');
  const firstId = await rows.first().getAttribute('id');
  const sort = library.getByLabel('Sort by');
  await expect(sort.getByRole('option')).toHaveText([
    'Title: A–Z',
    'Title: Z–A',
    'Shortest read first',
    'Longest read first',
  ]);
  await expect(sort).toHaveValue('title');
  await expect(
    library.getByRole('button', { name: 'Previous', exact: true }),
  ).toBeDisabled();
  await library.getByRole('button', { name: 'Next', exact: true }).click();
  await expect(results).toHaveText('Showing 21–40 of 1,200 results');
  await expect(rows.first()).not.toHaveAttribute('id', firstId ?? '');
  await library.getByRole('button', { name: 'Last', exact: true }).click();
  await expect(results).toHaveText('Showing 1,181–1,200 of 1,200 results');
  await expect(
    library.getByRole('button', { name: 'Next', exact: true }),
  ).toBeDisabled();
  await library.getByLabel('Per page').selectOption('50');
  await expect(results).toHaveText('Showing 1–50 of 1,200 results');
  await expect(rows).toHaveCount(50);
  await sort.selectOption('longest');
  await expect(rows.first().getByRole('cell').last()).toHaveText('25 min');
  await expect(sort).toHaveValue('longest');
  await sort.selectOption('shortest');
  await expect(rows.first().getByRole('cell').last()).toHaveText('4 min');
  await expect(sort).toHaveValue('shortest');
});

test('keeps the latest search during an in-flight response and handles literal wildcards', async ({
  page,
}) => {
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
  await page.goto('/search');
  const library = page.getByRole('region', { name: 'Resource library' });
  const search = library.getByLabel('Search resources');
  await search.fill('CakePHP');
  await expect.poll(() => intercepted).toBe(true);
  await search.fill('forms validation');
  release();
  await expect(library.getByRole('status')).toHaveText(
    'Showing 1–15 of 15 results',
  );
  await expect(search).toHaveValue('forms validation');
  await expect(search).toBeFocused();
  await expect(library.locator('tbody tr').first()).toContainText(
    'Input validation',
  );
  await search.fill('%');
  await expect(
    library.getByRole('heading', { name: 'No resources found' }),
  ).toBeVisible();
  await search.fill('keyboard');
  await search.press('Enter');
  await expect(library.getByRole('status')).toHaveText(
    'Showing 1–20 of 30 results',
  );
  await expect(library.getByRole('alert', { includeHidden: true })).toBeEmpty();
  await expect(page).toHaveURL('/search?q=keyboard');
});
