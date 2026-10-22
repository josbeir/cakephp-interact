import { expect, test } from './fixtures';
import { islandId, targetsIsland } from './requests';

test('saves repeatedly without replacing the form and renders conditional actions on demand', async ({
  page,
}) => {
  const errors: string[] = [];
  page.on('pageerror', (error) => errors.push(error.message));
  await page.goto('/renderless');
  const region = page.getByRole('region', { name: 'Renderless draft editor' });
  const title = region.getByLabel('Draft title');
  const id = await islandId(title);
  const original = await title.elementHandle();
  await title.fill('Saved without a morph');
  for (const count of [1, 2]) {
    const response = page.waitForResponse((reply) =>
      targetsIsland(reply.request(), id),
    );
    await title.press('Enter');
    expect(await (await response).json()).toMatchObject({
      html: null,
      state: { savedCount: count },
    });
    await expect(region.getByTestId('live-saves')).toHaveText(String(count));
    await expect(region.getByTestId('php-saves')).toHaveText('0');
    await expect(title).toBeFocused();
    expect(await original?.evaluate((element) => element.isConnected)).toBe(
      true,
    );
  }
  await region
    .getByRole('button', { name: 'Refresh view', exact: true })
    .click();
  await expect(region.getByTestId('php-saves')).toHaveText('2');
  await region
    .getByRole('button', { name: 'Send signal', exact: true })
    .click();
  await expect(region.getByTestId('live-signals')).toHaveText('1');
  await expect(region.getByTestId('php-signals')).toHaveText('0');
  await region
    .getByRole('button', { name: 'Send five signals and render', exact: true })
    .click();
  await expect(region.getByTestId('php-signals')).toHaveText('6');
  await expect(region.getByTestId('live-signals')).toHaveText('6');
  expect(errors).toEqual([]);
});

test('renders validation errors and clears them before returning to renderless saves', async ({
  page,
}) => {
  await page.goto('/renderless');
  const region = page.getByRole('region', { name: 'Renderless draft editor' });
  const title = region.getByLabel('Draft title');
  const body = region.getByLabel('Draft body');
  const id = await islandId(title);
  await body.fill('Preserve this draft through validation.');
  await title.fill('');
  let response = page.waitForResponse((reply) =>
    targetsIsland(reply.request(), id),
  );
  await title.press('Enter');
  expect((await response).status()).toBe(422);
  await expect(
    region.getByRole('alert').filter({ hasText: 'Enter a title.' }),
  ).toBeVisible();
  await expect(body).toHaveValue('Preserve this draft through validation.');
  await title.fill('Corrected title');
  response = page.waitForResponse((reply) =>
    targetsIsland(reply.request(), id),
  );
  await title.press('Enter');
  expect(await (await response).json()).toMatchObject({
    html: expect.any(String),
  });
  await expect(
    region.getByRole('alert').filter({ hasText: 'Enter a title.' }),
  ).toHaveCount(0);
  await expect(region.getByTestId('php-saves')).toHaveText('1');
  response = page.waitForResponse((reply) =>
    targetsIsland(reply.request(), id),
  );
  await title.press('Enter');
  expect(await (await response).json()).toMatchObject({ html: null });
  await expect(region.getByTestId('live-saves')).toHaveText('2');
  await expect(region.getByTestId('php-saves')).toHaveText('1');
});

test('protects repeated submissions and retains newer edits and selection during a delayed save', async ({
  page,
}) => {
  await page.goto('/renderless');
  const region = page.getByRole('region', { name: 'Renderless draft editor' });
  const title = region.getByLabel('Draft title');
  const id = await islandId(title);
  let release: () => void = () => {};
  const gate = new Promise<void>((resolve) => {
    release = resolve;
  });
  let requests = 0;
  await page.route('**/_interact/update', async (route) => {
    requests++;
    const reply = await route.fetch();
    await gate;
    await route.fulfill({ response: reply });
  });
  await title.fill('Submitted title');
  await region.locator('form').evaluate((form) => {
    if (form instanceof HTMLFormElement) {
      form.requestSubmit();
      form.requestSubmit();
    }
  });
  await expect(
    region.getByRole('button', { name: 'Save draft', exact: true }),
  ).toBeDisabled();
  await title.fill('Newer title');
  await title.evaluate((element) => {
    if (element instanceof HTMLInputElement) element.setSelectionRange(2, 6);
  });
  const response = page.waitForResponse((reply) =>
    targetsIsland(reply.request(), id),
  );
  release();
  expect(await (await response).json()).toMatchObject({
    html: null,
    state: { data: { title: 'Submitted title' } },
  });
  await expect(region.getByTestId('live-saves')).toHaveText('1');
  await expect(title).toHaveValue('Newer title');
  await expect(title).toBeFocused();
  expect(
    await title.evaluate((element) =>
      element instanceof HTMLInputElement
        ? [element.selectionStart, element.selectionEnd]
        : [],
    ),
  ).toEqual([2, 6]);
  await expect(
    region.getByRole('button', { name: 'Save draft', exact: true }),
  ).toBeEnabled();
  expect(requests).toBe(1);
});

test('reports a failed save without replaying it and accepts an explicit retry', async ({
  page,
}) => {
  await page.goto('/renderless');
  const region = page.getByRole('region', { name: 'Renderless draft editor' });
  let requests = 0;
  await page.route('**/_interact/update', async (route) => {
    requests++;
    if (requests === 1) await route.abort('failed');
    else await route.continue();
  });
  await region.getByLabel('Draft title').fill('Retry this draft');
  const save = region.getByRole('button', { name: 'Save draft', exact: true });
  await save.click();
  await expect(
    page.getByRole('alert').filter({ hasText: 'connection' }),
  ).toBeVisible();
  await expect(save).toBeEnabled();
  expect(requests).toBe(1);
  await save.click();
  await expect(region.getByTestId('live-saves')).toHaveText('1');
  await expect(region.getByTestId('php-saves')).toHaveText('0');
  expect(requests).toBe(2);
});
