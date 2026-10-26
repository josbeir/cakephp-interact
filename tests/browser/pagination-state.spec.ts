import { expect, test } from './fixtures';

test('one island paginates two Tables with separate settings, aliases, and history', async ({
  page,
}) => {
  await page.goto('/search/multiple?external=keep');
  const resources = page.getByRole('region', { name: 'Overview resources' });
  const articles = page.getByRole('region', { name: 'Overview articles' });
  const notes = page.getByLabel('Overview notes');
  await expect(resources.getByRole('status')).toHaveText(
    'Resources 1–20 of 1200',
  );
  await expect(articles.getByRole('status')).toHaveText('Articles 1–3 of 7');
  await notes.fill('Keep this draft');
  await resources.getByRole('link', { name: 'Next resources' }).click();
  await expect(resources.getByRole('status')).toHaveText(
    'Resources 21–40 of 1200',
  );
  await articles.getByRole('link', { name: 'Next articles' }).click();
  await expect(articles.getByRole('status')).toHaveText('Articles 4–6 of 7');
  await expect(resources.getByRole('status')).toHaveText(
    'Resources 21–40 of 1200',
  );
  await expect(notes).toHaveValue('Keep this draft');
  expect(new URL(page.url()).searchParams.get('resourcePage')).toBe('2');
  expect(new URL(page.url()).searchParams.get('articlePage')).toBe('2');
  expect(new URL(page.url()).searchParams.get('external')).toBe('keep');
  await page.goBack();
  await expect(articles.getByRole('status')).toHaveText('Articles 1–3 of 7');
  await expect(resources.getByRole('status')).toHaveText(
    'Resources 21–40 of 1200',
  );
  await page.goForward();
  await expect(articles.getByRole('status')).toHaveText('Articles 4–6 of 7');
  await resources.getByLabel('Resources per page').selectOption('50');
  await expect(resources.getByRole('status')).toHaveText(
    'Resources 1–50 of 1200',
  );
  await expect(articles.getByRole('status')).toHaveText('Articles 4–6 of 7');
  await resources.getByRole('link', { name: 'Sort resources' }).click();
  await expect(articles.getByRole('status')).toHaveText('Articles 4–6 of 7');
  await expect(notes).toHaveValue('Keep this draft');
});

test('multiple paginator links preserve both selections without JavaScript', async ({
  browser,
  appURL,
}) => {
  const context = await browser.newContext({
    javaScriptEnabled: false,
    baseURL: appURL,
  });
  try {
    const page = await context.newPage();
    await page.goto('/search/multiple?external=keep');
    const resources = page.getByRole('region', { name: 'Overview resources' });
    const articles = page.getByRole('region', { name: 'Overview articles' });
    await resources.getByRole('link', { name: 'Next resources' }).click();
    await articles.getByRole('link', { name: 'Next articles' }).click();
    await expect(resources.getByRole('status')).toHaveText(
      'Resources 21–40 of 1200',
    );
    await expect(articles.getByRole('status')).toHaveText('Articles 4–6 of 7');
    expect(new URL(page.url()).searchParams.get('external')).toBe('keep');
    await page.reload();
    await expect(resources.getByRole('status')).toHaveText(
      'Resources 21–40 of 1200',
    );
    await expect(articles.getByRole('status')).toHaveText('Articles 4–6 of 7');
  } finally {
    await context.close();
  }
});
