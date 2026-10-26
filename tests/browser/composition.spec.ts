import { expect, test } from './fixtures';

test('parent props arriving during initialization are applied after the child loads', async ({
  page,
}) => {
  let release: () => void = () => {};
  const held = new Promise<void>((resolve) => {
    release = resolve;
  });
  let intercepted = false;
  await page.route('**/_interact/update', async (route) => {
    const request: unknown = route.request().postDataJSON();
    if (
      typeof request === 'object' &&
      request !== null &&
      'initialize' in request &&
      request.initialize === true
    ) {
      const response = await route.fetch();
      intercepted = true;
      await held;
      await route.fulfill({ response });
    } else await route.continue();
  });
  await page.goto('/composition?loading=manual');
  const order = page.getByRole('region', {
    name: 'Parent draft',
    exact: true,
  });
  await order
    .getByRole('button', { name: 'Load content', exact: true })
    .click();
  await expect.poll(() => intercepted).toBe(true);
  await order.getByLabel('Resource category').selectOption('alpine');
  await expect(order.locator('..')).not.toHaveAttribute('aria-busy');
  release();
  const picker = page.getByRole('region', {
    name: 'Resource picker',
    exact: true,
  });
  await expect(picker.locator('[data-category]')).toHaveText('alpine');
});

test('a delayed child action cannot replace a newer parent reset', async ({
  page,
}) => {
  let release: () => void = () => {};
  const held = new Promise<void>((resolve) => {
    release = resolve;
  });
  let intercepted = false;
  let presetRequests = 0;
  await page.route('**/_interact/update', async (route) => {
    const request: unknown = route.request().postDataJSON();
    if (
      typeof request === 'object' &&
      request !== null &&
      'action' in request &&
      request.action === 'preset'
    ) {
      presetRequests++;
      const response = await route.fetch();
      intercepted = true;
      await held;
      await route.fulfill({ response });
    } else await route.continue();
  });
  await page.goto('/composition');
  const order = page.getByRole('region', {
    name: 'Parent draft',
    exact: true,
  });
  const range = page.getByRole('region', { name: 'Date range', exact: true });
  await range.getByRole('button', { name: 'Use January' }).click();
  await expect.poll(() => intercepted).toBe(true);
  await order.getByRole('button', { name: 'Clear selection' }).click();
  await expect(order.locator('..')).not.toHaveAttribute('aria-busy');
  release();
  await expect(range.getByLabel('Start date')).toHaveValue('');
  await expect(range.getByLabel('End date')).toHaveValue('');
  await expect(order.locator('[data-range]')).toHaveText('/');
  await expect(range.locator('..')).not.toHaveAttribute('aria-busy');
  expect(presetRequests).toBe(1);
});

test('reactive props refresh an existing child and modelable PHP changes reach the parent', async ({
  page,
}) => {
  await page.goto('/composition');
  const order = page.getByRole('region', {
    name: 'Parent draft',
    exact: true,
  });
  const picker = page.getByRole('region', {
    name: 'Resource picker',
    exact: true,
  });
  const identity = await picker.locator('..').getAttribute('data-interact-id');
  await order
    .getByRole('button', { name: 'Review selection', exact: true })
    .click();
  await expect(
    order.getByText('Choose a resource from the current category.', {
      exact: true,
    }),
  ).toBeVisible();
  await picker.getByLabel('Picker notes').fill('Keep this draft');
  await picker.getByRole('button').first().click();
  await expect(order.locator('[data-selection]')).not.toHaveText('None');
  await order.getByLabel('Resource category').selectOption('alpine');
  await expect(picker.locator('[data-category]')).toHaveText('alpine');
  await expect(order.locator('[data-selection]')).toHaveText('None');
  await expect(picker.getByLabel('Picker notes')).toHaveValue(
    'Keep this draft',
  );
  await expect(picker.locator('..')).toHaveAttribute(
    'data-interact-id',
    identity ?? '',
  );
  await picker.getByRole('button').first().click();
  await expect(order.locator('[data-selection]')).not.toHaveText('None');
  await order
    .getByRole('button', { name: 'Review selection', exact: true })
    .click();
  await expect(order.getByRole('status')).not.toBeEmpty();
});

test('multiple values mirror immediately and renderless changes flow in both directions', async ({
  page,
}) => {
  await page.goto('/composition');
  const order = page.getByRole('region', {
    name: 'Parent draft',
    exact: true,
  });
  const range = page.getByRole('region', { name: 'Date range', exact: true });
  await range.getByLabel('Start date').fill('2026-02-03');
  await range.getByLabel('End date').fill('2026-02-10');
  await expect(order.locator('[data-range]')).toHaveText(
    '2026-02-03 / 2026-02-10',
  );
  await range.getByRole('button', { name: 'Use January' }).click();
  await expect(order.locator('[data-range]')).toHaveText(
    '2026-01-01 / 2026-01-31',
  );
  await order.getByRole('button', { name: 'Clear selection' }).click();
  await expect(range.getByLabel('Start date')).toHaveValue('');
  await expect(range.getByLabel('End date')).toHaveValue('');
  await expect(order.locator('[data-range]')).toHaveText('/');
});

test('a waiting manual child receives the latest parent props without loading early', async ({
  page,
}) => {
  await page.goto('/composition?loading=manual');
  const order = page.getByRole('region', {
    name: 'Parent draft',
    exact: true,
  });
  const load = order.getByRole('button', { name: 'Load content', exact: true });
  await expect(load).toBeVisible();
  await order.getByLabel('Resource category').selectOption('alpine');
  await expect(order.getByLabel('Resource category')).toHaveValue('alpine');
  await expect(load).toBeVisible();
  await load.click();
  const picker = page.getByRole('region', {
    name: 'Resource picker',
    exact: true,
  });
  await expect(picker.locator('[data-category]')).toHaveText('alpine');
  await picker.getByRole('button').first().click();
  await expect(order.locator('[data-selection]')).not.toHaveText('None');
});
