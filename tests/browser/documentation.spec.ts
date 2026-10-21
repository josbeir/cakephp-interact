import { execFile } from 'node:child_process';
import { readFile } from 'node:fs/promises';
import { expect, test, type Page } from '@playwright/test';

function render(alias: string, input = '', loading = 'eager'): Promise<string> {
  return new Promise((resolve, reject) => {
    const child = execFile(
      'php',
      ['tests/docs/render.php', alias, loading],
      { maxBuffer: 1024 * 1024 },
      (error, stdout, stderr) => {
        if (error || stderr) reject(error ?? new Error(stderr));
        else resolve(stdout);
      },
    );
    child.stdin?.end(input);
  });
}

// Exercise the imported PHP/JS source. Middleware security is covered by the app suites.
async function example(
  page: Page,
  alias: string,
  scripts: string[] = [],
  styles: string[] = [],
  loading = 'eager',
) {
  const errors: string[] = [];
  page.on('pageerror', (error) => errors.push(error.message));
  await page.route('**/docs-update', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      body: await render(alias, route.request().postData() ?? ''),
    });
  });
  await page.route('**/documentation-example', async (route) => {
    await route.fulfill({
      contentType: 'text/html',
      headers: { 'Content-Security-Policy': "script-src 'self'" },
      body: `<!doctype html><html><head><meta charset="utf-8">${styles.map((href) => `<link rel="stylesheet" href="${href}">`).join('')}</head><body>${await render(alias, '', loading)}${scripts.map((src) => `<script defer src="${src}"></script>`).join('')}<script defer src="/interact/js/runtime.js"></script></body></html>`,
    });
  });
  await page.goto('/documentation-example');
  return errors;
}

test('the article example preserves entity errors and saves corrected input', async ({
  page,
}) => {
  const errors = await example(page, 'article-form');
  // Bypass the native length guard to verify server validation and entity errors.
  await page
    .getByLabel('Title', { exact: true })
    .evaluate((input) => input.removeAttribute('maxlength'));
  await page.getByLabel('Title', { exact: true }).fill('x'.repeat(121));
  await page.getByRole('button', { name: 'Save article' }).click();
  await expect(page.locator('.error-message')).toContainText('120');
  await page.getByLabel('Title', { exact: true }).fill('An example article');
  await page.getByRole('button', { name: 'Save article' }).click();
  await expect(page.getByRole('status')).toHaveText('Article saved.');
  await expect(page.locator('.error-message')).toHaveCount(0);
  expect(errors).toEqual([]);
});

test('the search example filters and resets pagination while preserving focus', async ({
  page,
}) => {
  const errors = await example(page, 'resource-search');
  await page.getByRole('button', { name: 'Next', exact: true }).click();
  await expect(page.getByRole('status')).toContainText('Page 2');
  await page.getByLabel('Search resources').fill('resource 01');
  await expect(page.getByRole('status')).toHaveText('1 results. Page 1 of 1.');
  await expect(page.getByLabel('Search resources')).toBeFocused();
  expect(errors).toEqual([]);
});

test('the select example keeps its real widget through PHP updates and resets', async ({
  page,
}) => {
  await page.route('**/docs-tom-select.js', async (route) => {
    await route.fulfill({
      contentType: 'text/javascript',
      body: await readFile(
        'node_modules/tom-select/dist/js/tom-select.base.min.js',
        'utf8',
      ),
    });
  });
  await page.route('**/docs-tom-select.css', async (route) => {
    await route.fulfill({
      contentType: 'text/css',
      body: await readFile(
        'node_modules/tom-select/dist/css/tom-select.default.css',
        'utf8',
      ),
    });
  });
  await page.route('**/docs-country-picker.js', async (route) => {
    await route.fulfill({
      contentType: 'text/javascript',
      body: await readFile(
        'tests/docs/app/webroot/js/country-picker.js',
        'utf8',
      ),
    });
  });
  const errors = await example(
    page,
    'country-picker',
    ['/docs-tom-select.js', '/docs-country-picker.js'],
    ['/docs-tom-select.css'],
  );
  await page.getByRole('button', { name: 'Confirm country' }).click();
  await expect(
    page.getByRole('alert').filter({ hasText: 'Choose a country' }),
  ).toBeVisible();
  await page
    .getByRole('combobox', { name: 'Country', exact: true })
    .fill('Belg');
  await page
    .getByRole('listbox')
    .getByRole('option', { name: 'Belgium', exact: true })
    .click();
  await page.getByRole('button', { name: 'Confirm country' }).click();
  await expect(page.getByRole('status')).toHaveText('Country confirmed.');
  await page
    .locator('#picker-country')
    .evaluate((element) => element.setAttribute('data-instance', 'retained'));
  await expect(page.locator('[data-interact-id]')).not.toHaveAttribute(
    'aria-busy',
    'true',
  );
  await page
    .getByRole('combobox', { name: 'Country', exact: true })
    .press('Tab');
  await expect(
    page.getByRole('combobox', { name: 'Country', exact: true }),
  ).toHaveAttribute('aria-expanded', 'false');
  await page.getByRole('button', { name: 'Reset selection' }).click();
  await expect(page.locator('#picker-country')).toHaveValue('');
  await expect(page.locator('#picker-country')).toHaveAttribute(
    'data-instance',
    'retained',
  );
  await expect(page.locator('.ts-wrapper')).toHaveCount(1);
  expect(errors).toEqual([]);
});

test('the chart example updates and disposes its library instance under CSP', async ({
  page,
}) => {
  // Controlled chart API boundary: verify wrapper lifecycle and cloned data, not Chart.js rendering.
  await page.route('**/docs-chart-library.js', async (route) => {
    await route.fulfill({
      contentType: 'text/javascript',
      body: `/**
       * Records the application wrapper's data updates and cleanup at the chart API boundary.
       */
      window.Chart = class {
      constructor(canvas, options) { this.canvas = canvas; this.data = options.data; this.updates = 0; }
      update() { this.canvas.dataset.updates = String(++this.updates); this.canvas.dataset.chart = JSON.stringify(this.data); }
      destroy() { document.body.dataset.chartDestroyed = 'true'; }
    };`,
    });
  });
  await page.route('**/docs-sales-chart.js', async (route) => {
    await route.fulfill({
      contentType: 'text/javascript',
      body: await readFile('tests/docs/app/webroot/js/sales-chart.js', 'utf8'),
    });
  });
  const errors = await example(page, 'sales-chart', [
    '/docs-chart-library.js',
    '/docs-sales-chart.js',
  ]);
  const canvas = page.getByRole('img', { name: 'Daily orders' });
  await canvas.evaluate((element) =>
    element.setAttribute('data-instance', 'retained'),
  );
  await page.getByRole('button', { name: 'Refresh chart' }).click();
  await expect(canvas).toHaveAttribute('data-updates', '1');
  await expect(canvas).toHaveAttribute('data-chart', /Orders/);
  await expect(canvas).toHaveAttribute('data-instance', 'retained');
  await page.getByRole('button', { name: 'Refresh chart' }).click();
  await expect(canvas).toHaveAttribute('data-updates', '2');
  await page
    .locator('[data-interact-id]')
    .evaluate((element) => element.remove());
  await expect(page.locator('body')).toHaveAttribute(
    'data-chart-destroyed',
    'true',
  );
  expect(errors).toEqual([]);
});

test('loads the resource-count documentation example through the standard bridge', async ({
  page,
}) => {
  const errors = await example(page, 'resource-count', [], [], 'manual');
  await expect(page.getByText('25 matching resources')).toHaveCount(0);
  await page.getByRole('button', { name: 'Load content', exact: true }).click();
  await expect(
    page.getByRole('region', { name: 'Resource count' }),
  ).toContainText('25 matching resources');
  await page.getByRole('button', { name: 'Refresh count' }).click();
  await expect(
    page.getByRole('region', { name: 'Resource count' }),
  ).toContainText('25 matching resources');
  expect(errors).toEqual([]);
});
