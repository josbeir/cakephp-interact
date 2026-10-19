import { expect, test } from '@playwright/test';

test('search preserves matching child drafts and local Alpine state', async ({
  page,
}) => {
  const errors: string[] = [];
  page.on('pageerror', (error) => errors.push(error.message));
  await page.goto('/');
  const list = page.getByRole('region', { name: 'Articles', exact: true });
  const editor = page.getByRole('article', { name: 'Article 2', exact: true });
  await editor.getByLabel('Article title').fill('Unsaved local draft');
  await list.getByRole('button', { name: 'Toggle help' }).click();
  await expect(
    list.getByText('Search and edit without leaving the page.'),
  ).toBeVisible();
  await list.getByLabel('Search articles').fill('CakePHP');
  await expect(page.getByRole('article')).toHaveCount(1);
  await expect(editor.getByLabel('Article title')).toHaveValue(
    'Unsaved local draft',
  );
  await expect(
    list.getByText('Search and edit without leaving the page.'),
  ).toBeVisible();
  await expect(list.getByLabel('Search articles')).toBeFocused();
  expect(errors).toEqual([]);
});

test('validates an explicit save without reloading the page', async ({
  page,
}) => {
  await page.goto('/');
  const editor = page.getByRole('article', { name: 'Article 1', exact: true });
  await editor.getByLabel('Article title').fill('');
  await editor.getByRole('button', { name: 'Save article' }).click();
  await expect(
    editor.getByRole('alert').filter({ hasText: 'A title is required.' }),
  ).toBeVisible();
  await editor.getByLabel('Article title').fill('Alpine patterns');
  await editor.getByRole('button', { name: 'Save article' }).click();
  await expect(editor.getByRole('status')).toHaveText('Saved.');
  await expect(page).toHaveURL('/');
});

test('dependent choices preserve notes and reject an empty selection', async ({
  page,
}) => {
  await page.goto('/');
  const choices = page.getByRole('region', {
    name: 'Dependent choices',
    exact: true,
  });
  await choices.getByLabel('Notes').fill('Keep my draft');
  await choices.getByLabel('Country', { exact: true }).selectOption('NL');
  await expect(
    choices
      .getByLabel('City', { exact: true })
      .getByRole('option', { name: 'Amsterdam' }),
  ).toHaveCount(1);
  await expect(choices.getByLabel('Notes')).toHaveValue('Keep my draft');
  await choices.getByRole('button', { name: 'Confirm city' }).click();
  await expect(choices.getByRole('alert')).toContainText('Choose a city');
});

test('whole-page islands retain their layout through pagination', async ({
  page,
}) => {
  await page.goto('/page');
  await expect(
    page.getByRole('heading', { name: 'Whole-page listing' }),
  ).toBeVisible();
  await page.getByRole('button', { name: 'Next page' }).click();
  await expect(page.getByText('Page 2', { exact: true })).toBeVisible();
  await expect(page.getByRole('navigation')).toHaveCount(1);
});

test('typing during a delayed response retains the newer draft', async ({
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
  await page.goto('/');
  const search = page.getByLabel('Search articles');
  await search.fill('Cake');
  await expect.poll(() => intercepted).toBe(true);
  await search.fill('Alpine');
  release();
  await expect(page.getByRole('article')).toHaveCount(1);
  await expect(search).toHaveValue('Alpine');
  await expect(
    page.getByRole('article', { name: 'Article 1', exact: true }),
  ).toBeVisible();
});
