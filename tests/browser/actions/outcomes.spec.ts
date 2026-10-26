import { readFile } from 'node:fs/promises';
import { expect, test } from '../fixtures';

test('validation preserves the form and a successful save redirects to its native page', async ({
  page,
}) => {
  await page.goto('/outcomes');
  const region = page.getByRole('region', { name: 'Action outcomes' });
  await region
    .getByRole('button', { name: 'Save and visit article', exact: true })
    .click();
  await expect(region).toContainText('A title is required.');
  await expect(page).toHaveURL('/outcomes');
  await region.getByLabel('Title').fill('Redirected article');
  await region
    .getByRole('button', { name: 'Save and visit article', exact: true })
    .click();
  await expect(page).toHaveURL(/\/outcomes\/saved\?id=\d+$/);
  await expect(
    page.getByRole('heading', { name: 'Redirected article' }),
  ).toBeVisible();
  await page.goBack();
  await expect(page).toHaveURL('/outcomes');
});

test('replace redirects omit the form page from Back history', async ({
  page,
}) => {
  await page.goto('/search');
  await page
    .getByRole('link', { name: 'Action outcomes', exact: true })
    .click();
  await page
    .getByLabel('Title', { exact: true })
    .fill('Replace history article');
  await page.getByRole('button', { name: 'Save and replace history' }).click();
  await expect(page).toHaveURL(/\/outcomes\/saved\?id=\d+$/);
  await page.goBack();
  await expect(page).toHaveURL('/search');
});

test('downloads use native attachment headers and retain page drafts and focus', async ({
  page,
}, testInfo) => {
  await page.goto('/outcomes');
  const title = page.getByLabel('Title', { exact: true });
  await title.fill('Unsent invoice notes');
  const download = page.waitForEvent('download');
  await page
    .getByRole('button', { name: 'Download invoice', exact: true })
    .click();
  const file = await download;
  expect(file.suggestedFilename()).toBe('invoice.csv');
  const path = testInfo.outputPath('invoice.csv');
  await file.saveAs(path);
  expect(await readFile(path, 'utf8')).toBe('Item,Amount\nWorkshop,125.00\n');
  await expect(page.getByRole('status')).toHaveText('Requested 1 downloads.');
  await expect(title).toHaveValue('Unsent invoice notes');
  await expect(page).toHaveURL('/outcomes');
  await expect(
    page.getByRole('button', { name: 'Download invoice' }),
  ).toBeFocused();
});

test('the download endpoint checks current permission independently of island state', async ({
  page,
}) => {
  await page.goto('/outcomes');
  const denied = await page.request.get('/outcomes/invoice');
  expect(denied.status()).toBe(200);
  const csrf = await page
    .locator('[data-interact-csrf]')
    .getAttribute('data-interact-csrf');
  if (!csrf) throw new Error('Missing CSRF token.');
  await page.request.post('/demo/role/viewer', {
    headers: { 'X-CSRF-Token': csrf },
  });
  const response = await page.request.get('/outcomes/invoice');
  expect(response.status()).toBe(403);
  expect(await response.text()).not.toContain('Workshop,125.00');
});

test('an external redirect uses the same action protocol', async ({ page }) => {
  await page.goto('/outcomes');
  await page.route('https://example.com/', (route) =>
    route.fulfill({
      contentType: 'text/html',
      body: '<h1>External destination</h1>',
    }),
  );
  await page.evaluate(() => {
    const button = document.createElement('button');
    button.textContent = 'External';
    button.setAttribute('@click', "$server.call('external')");
    document.querySelector('[data-interact-id]')?.append(button);
  });
  await page.getByRole('button', { name: 'External', exact: true }).click();
  await expect(page).toHaveURL('https://example.com/');
  await expect(
    page.getByRole('heading', { name: 'External destination' }),
  ).toBeVisible();
});

test('same-page fragment redirects reload with a fresh interactive island', async ({
  page,
}) => {
  await page.goto('/outcomes');
  const navigation = page.waitForRequest(
    (request) =>
      request.isNavigationRequest() &&
      new URL(request.url()).pathname === '/outcomes',
  );
  await page.evaluate(() => {
    const button = document.createElement('button');
    button.textContent = 'Fragment redirect';
    button.setAttribute('@click', "$server.call('fragment')");
    document.querySelector('[data-interact-id]')?.append(button);
  });
  await page
    .getByRole('button', { name: 'Fragment redirect', exact: true })
    .click();
  await navigation;
  await expect(page).toHaveURL('/outcomes#main');
  await expect(
    page.getByRole('button', { name: 'Fragment redirect', exact: true }),
  ).toHaveCount(0);
  const download = page.waitForEvent('download');
  await page
    .getByRole('button', { name: 'Download invoice', exact: true })
    .click();
  expect((await download).suggestedFilename()).toBe('invoice.csv');
  await expect(page.getByRole('status')).toHaveText('Requested 1 downloads.');
});
