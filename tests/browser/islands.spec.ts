import { expect, test } from './fixtures';

test('playground counters keep independent state and accept typed action arguments', async ({
  page,
}) => {
  await page.goto('/playground');
  const first = page.getByRole('region', {
    name: 'First counter',
    exact: true,
  });
  const second = page.getByRole('region', {
    name: 'Second counter',
    exact: true,
  });
  await first.getByRole('button', { name: 'Add five' }).click();
  await expect(first.locator('.counter-value')).toHaveText('Count: 5');
  await expect(second.locator('.counter-value')).toHaveText('Count: 5');
  await second.getByRole('button', { name: 'Decrease by one' }).click();
  await expect(second.locator('.counter-value')).toHaveText('Count: 4');
  await expect(first.locator('.counter-value')).toHaveText('Count: 5');
  await first.getByRole('button', { name: 'Reset' }).click();
  await expect(first.locator('.counter-value')).toHaveText('Count: 0');
});

test('workshop planner recalculates a quote and validates conditional company details', async ({
  page,
}) => {
  await page.goto('/playground');
  const planner = page.getByRole('region', {
    name: 'Workshop planner',
    exact: true,
  });
  await expect(planner.locator('.quote-total')).toHaveText('€90.00');
  await planner.getByLabel('Workshop format').selectOption('studio');
  await expect(planner.locator('.quote-total')).toHaveText('€150.00');
  await planner.getByLabel(/^Printed materials/).check();
  await expect(planner.locator('.quote-total')).toHaveText('€180.00');
  await planner.getByLabel(/^Session recording/).check();
  await expect(planner.locator('.quote-total')).toHaveText('€210.00');
  await planner.getByLabel(/^Book for a company/).check();
  await expect(planner.getByLabel('Company name')).toBeVisible();
  await planner.getByRole('button', { name: 'Confirm quote' }).click();
  await expect(
    planner.getByRole('alert').filter({ hasText: 'Enter a company name' }),
  ).toBeVisible();
  await planner.getByLabel('Company name').fill('Cake Studio');
  await planner.getByRole('button', { name: 'Confirm quote' }).click();
  await expect(planner.getByRole('status')).toContainText('Quote confirmed.');
  await planner.getByLabel(/^Book for a company/).uncheck();
  await expect(planner.getByLabel('Company name')).toHaveCount(0);
  await planner.getByLabel(/^Book for a company/).check();
  await expect(planner.getByLabel('Company name')).toHaveValue('Cake Studio');
});

test('task board validates, adds, completes, and removes a task without losing its draft', async ({
  page,
}) => {
  await page.goto('/playground');
  const board = page.getByRole('region', { name: 'Task board', exact: true });
  await board.getByRole('button', { name: 'Add task', exact: true }).click();
  await expect(
    board.getByRole('alert').filter({ hasText: 'Use 1 to 80 characters' }),
  ).toBeVisible();
  await board.getByLabel('Add a task').fill('Try typed actions');
  await board.getByRole('button', { name: 'Add task', exact: true }).click();
  await expect(
    board.getByText('Try typed actions', { exact: true }),
  ).toBeVisible();
  await expect(board.getByLabel('Add a task')).toHaveValue('');
  await board.getByLabel('Add a task').fill('Keep my next task');
  const complete = board.getByRole('button', {
    name: 'Complete Try typed actions',
    exact: true,
  });
  await complete.click();
  await expect(complete).toHaveAttribute('aria-pressed', 'true');
  await expect(board.getByLabel('Add a task')).toHaveValue('Keep my next task');
  await board
    .getByRole('button', { name: 'Remove Try typed actions', exact: true })
    .click();
  await expect(
    board.getByText('Try typed actions', { exact: true }),
  ).toHaveCount(0);
  await expect(board.getByLabel('Add a task')).toHaveValue('Keep my next task');
});

test('demo pages keep their navigation and forms within a narrow viewport', async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 844 });
  for (const path of ['/', '/playground', '/search', '/page']) {
    await page.goto(path);
    await expect(
      page.getByRole('navigation', { name: 'Demo pages' }),
    ).toBeVisible();
    await expect(page.locator('main h1')).toBeVisible();
    await expect(
      page.locator('link[rel="stylesheet"][href="/demo.css"]'),
    ).toHaveAttribute('href', '/demo.css');
    await expect
      .poll(() =>
        page.evaluate(
          () => document.documentElement.scrollWidth <= window.innerWidth,
        ),
      )
      .toBe(true);
  }
});

test('submits with a foreign CakePHP CSRF cookie already present', async ({
  page,
  context,
  baseURL,
}) => {
  const foreignCookie = Buffer.from('x'.repeat(56)).toString('base64');
  await context.addCookies([
    {
      name: 'csrfToken',
      value: foreignCookie,
      url: baseURL ?? 'http://127.0.0.1:8765',
    },
  ]);
  await page.goto('/');
  const cookies = await context.cookies();
  expect(
    cookies.find((cookie) => cookie.name === 'interact_demo_csrf'),
  ).toBeDefined();
  expect(cookies.find((cookie) => cookie.name === 'csrfToken')?.value).toBe(
    foreignCookie,
  );
  const editor = page.getByRole('article', { name: 'Article 1', exact: true });
  const response = page.waitForResponse((response) =>
    response.url().endsWith('/_interact/update'),
  );
  await editor.getByRole('button', { name: 'Save article' }).click();
  const update = await response;
  expect(update.status()).toBe(200);
  expect(update.headers()['content-type']).toContain('application/json');
  await expect(editor.getByRole('status')).toHaveText('Saved.');
});

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
  const title = editor.getByLabel('Article title');
  await expect(title).toHaveAttribute('required', 'required');
  await title.fill('');
  await expect
    .poll(() =>
      title.evaluate((input: HTMLInputElement) => input.validity.valueMissing),
    )
    .toBe(true);

  // Bypass browser validation to exercise the server's independent validation boundary.
  await editor.locator('form').evaluate((form: HTMLFormElement) => {
    form.noValidate = true;
  });
  const failedRequest = page.waitForResponse((response) =>
    response.url().endsWith('/_interact/update'),
  );
  await editor.getByRole('button', { name: 'Save article' }).click();
  const failedResponse = await failedRequest;
  expect(failedResponse.status()).toBe(422);
  expect(await failedResponse.json()).toMatchObject({
    errors: { title: ['A title is required.'] },
    events: [],
  });
  await expect(
    editor.getByRole('alert').filter({ hasText: 'A title is required.' }),
  ).toBeVisible();
  await expect(title).toHaveValue('');
  await expect(title).toHaveAttribute('aria-invalid', 'true');
  await expect(title).toHaveAccessibleDescription('A title is required.');
  await title.fill('Alpine patterns');
  await editor.getByRole('button', { name: 'Save article' }).click();
  await expect(editor.getByRole('status')).toHaveText('Saved.');
  await expect(title).not.toHaveAttribute('aria-invalid', 'true');
  await expect(
    editor.getByRole('alert').filter({ hasText: 'A title is required.' }),
  ).toHaveCount(0);
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
  await choices
    .getByRole('combobox', { name: 'Country', exact: true })
    .selectOption('NL');
  await expect(
    choices
      .getByRole('combobox', { name: 'City', exact: true })
      .getByRole('option', { name: 'Amsterdam' }),
  ).toHaveCount(1);
  await expect(choices.getByLabel('Notes')).toHaveValue('Keep my draft');
  await choices.getByRole('button', { name: 'Confirm city' }).click();
  await expect(
    choices.getByRole('alert').filter({ hasText: 'Choose a city' }),
  ).toBeVisible();
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
