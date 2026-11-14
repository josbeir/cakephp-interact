import { readFile } from 'node:fs/promises';
import { expect, test } from './fixtures';
import { islandId, targetsIsland } from './requests';

test('accepting the expiry prompt reloads without replaying the action', async ({
  page,
}) => {
  await page.clock.install({ time: new Date('2026-10-20T12:00:00Z') });
  await page.clock.pauseAt(new Date('2026-10-20T12:00:01Z'));
  await page.goto('/playground');
  const region = page.getByRole('region', {
    name: 'Failure recovery',
    exact: true,
  });
  const original = await islandId(region);
  let updates = 0;
  page.on('request', (request) => {
    if (targetsIsland(request, original)) updates++;
  });
  await region
    .getByLabel('Recovery draft', { exact: true })
    .fill('unsaved work');
  page.once('dialog', async (dialog) => {
    expect(dialog.type()).toBe('confirm');
    expect(dialog.message()).toContain('Unsaved changes will be lost');
    await dialog.accept();
  });
  await Promise.all([
    page.waitForEvent('load'),
    region
      .getByRole('button', { name: 'Simulate expiry', exact: true })
      .click(),
  ]);
  await page.clock.resume();
  expect(await islandId(region)).not.toBe(original);
  await expect(
    region.getByLabel('Recovery draft', { exact: true }),
  ).toHaveValue('');
  await expect(region.getByRole('status')).toHaveText('Accepted 0 times.');
  expect(updates).toBe(1);
});

test('dismissing expiry keeps the draft editable and cancels automatic updates', async ({
  page,
}) => {
  await page.clock.install({ time: new Date('2026-10-20T12:00:00Z') });
  await page.clock.pauseAt(new Date('2026-10-20T12:00:01Z'));
  await page.goto('/playground');
  const region = page.getByRole('region', {
    name: 'Failure recovery',
    exact: true,
  });
  const id = await islandId(region);
  let updates = 0;
  page.on('request', (request) => {
    if (targetsIsland(request, id)) updates++;
  });
  const dialogs: string[] = [];
  page.on('dialog', async (dialog) => {
    dialogs.push(dialog.message());
    await dialog.dismiss();
  });
  await region
    .getByLabel('Recovery draft', { exact: true })
    .fill('keep this text');
  await region
    .getByRole('button', { name: 'Simulate expiry', exact: true })
    .click();
  await page.clock.resume();
  await expect(region.getByRole('alert')).toContainText(
    'This page has expired',
  );
  await region
    .getByLabel('Recovery draft', { exact: true })
    .fill('still editable');
  await page.clock.runFor(1000);
  await expect(region.getByLabel('Draft export')).toHaveValue(/still editable/);
  await expect(
    region.getByRole('button', { name: 'Accept draft' }),
  ).toBeDisabled();
  expect(updates).toBe(1);
  expect(dialogs).toHaveLength(1);
});

test('a custom dialog suppresses only default expiry presentation', async ({
  page,
}) => {
  const guide = await readFile(
    new URL('../../docs/guide/failures.md', import.meta.url),
    'utf8',
  );
  const example = guide
    .split('::: details Custom dialog example')[1]
    ?.split('\n:::')[0];
  const markup = example?.match(/```html\n([\s\S]*?)```/)?.[1];
  const script = example?.match(/```js\n([\s\S]*?)```/)?.[1];
  if (!markup || !script)
    throw new Error('Missing documented Alpine recovery example.');
  await page.route('**/expiry-recovery.js', (route) =>
    route.fulfill({ contentType: 'text/javascript', body: script }),
  );
  await page.route('**/playground', async (route) => {
    const response = await route.fetch();
    const body = (await response.text())
      .replace(
        '<head>',
        '<head><script src="/expiry-recovery.js" defer></script>',
      )
      .replace('</body>', markup + '</body>');
    await route.fulfill({ response, body });
  });
  await page.goto('/playground');
  const nativeDialogs: string[] = [];
  page.on('dialog', async (dialog) => {
    nativeDialogs.push(dialog.message());
    await dialog.dismiss();
  });
  const region = page.getByRole('region', {
    name: 'Failure recovery',
    exact: true,
  });
  await region
    .getByRole('button', { name: 'Simulate expiry', exact: true })
    .click();
  const dialog = page.getByRole('dialog', { name: 'This page has expired' });
  await expect(dialog).toBeVisible();
  await expect(
    dialog.getByRole('button', { name: 'Keep this page open' }),
  ).toBeFocused();
  await dialog.getByRole('button', { name: 'Keep this page open' }).click();
  await expect(dialog).not.toBeVisible();
  await expect(region.getByRole('alert')).toContainText(
    'This page has expired',
  );
  await expect(
    region.getByRole('button', { name: 'Accept draft' }),
  ).toBeDisabled();
  expect(nativeDialogs).toEqual([]);

  const navigation = page.getByRole('navigation', { name: 'Demo pages' });
  await navigation
    .getByRole('link', { name: 'Navigation', exact: true })
    .click();
  await expect(page).toHaveURL(/\/navigation$/);
  const notebook = page.locator(
    '[x-interact\\:persist="notebook"] [data-interact-id]',
  );
  const notebookId = await islandId(notebook);
  await page.route('**/_interact/update', async (route) => {
    if (!targetsIsland(route.request(), notebookId)) {
      await route.continue();
      return;
    }
    await route.fulfill({
      status: 409,
      json: {
        version: 1,
        error: { code: 'snapshot_expired', message: 'Expired notebook' },
      },
    });
  });
  const rejection = page.waitForResponse((response) =>
    targetsIsland(response.request(), notebookId),
  );
  await notebook
    .getByRole('button', { name: 'Remember draft', exact: true })
    .click();
  expect(await (await rejection).json()).toMatchObject({
    error: { code: 'snapshot_expired' },
  });
  await expect.poll(() => nativeDialogs.length).toBe(1);

  await navigation
    .getByRole('link', { name: 'Playground', exact: true })
    .click();
  await expect(page).toHaveURL(/\/playground$/);
  await region
    .getByRole('button', { name: 'Simulate expiry', exact: true })
    .click();
  await expect(dialog).toBeVisible();
  await Promise.all([
    page.waitForEvent('load'),
    dialog.getByRole('button', { name: 'Reload page', exact: true }).click(),
  ]);
  await expect(region.getByRole('status')).toHaveText('Accepted 0 times.');
  expect(nativeDialogs).toHaveLength(1);
});

test('islands share one prompt until an enhanced visit succeeds', async ({
  page,
}) => {
  await page.goto('/playground');
  await page.evaluate(() => {
    window.name = 'expiry document';
  });
  const first = page.getByRole('region', {
    name: 'First counter',
    exact: true,
  });
  const second = page.getByRole('region', {
    name: 'Second counter',
    exact: true,
  });
  const ids = [await islandId(first), await islandId(second)];
  await page.route('**/_interact/update', async (route) => {
    if (!ids.some((id) => targetsIsland(route.request(), id))) {
      await route.continue();
      return;
    }
    await route.fulfill({
      status: 409,
      json: {
        version: 1,
        error: { code: 'snapshot_expired', message: 'Expired counter' },
      },
    });
  });
  const dialogs: string[] = [];
  page.on('dialog', async (dialog) => {
    dialogs.push(dialog.message());
    await dialog.dismiss();
  });
  await first.getByRole('button', { name: 'Add five' }).click();
  await expect(first.getByRole('alert')).toHaveText('Expired counter');
  await second.getByRole('button', { name: 'Add five' }).click();
  await expect(second.getByRole('alert')).toHaveText('Expired counter');
  expect(dialogs).toHaveLength(1);
  const navigation = page.getByRole('navigation', { name: 'Demo pages' });
  await page.evaluate(() => {
    document.addEventListener(
      'interact:navigate',
      (event) => event.preventDefault(),
      { once: true },
    );
  });
  await navigation
    .getByRole('link', { name: 'Navigation', exact: true })
    .click();
  await expect(page).toHaveURL(/\/playground$/);
  const recovery = page.getByRole('region', {
    name: 'Failure recovery',
    exact: true,
  });
  await recovery
    .getByRole('button', { name: 'Simulate expiry', exact: true })
    .click();
  await expect(recovery.getByRole('alert')).toContainText(
    'This page has expired',
  );
  expect(dialogs).toHaveLength(1);
  await navigation
    .getByRole('link', { name: 'Navigation', exact: true })
    .click();
  await expect(page).toHaveURL(/\/navigation$/);
  await navigation
    .getByRole('link', { name: 'Playground', exact: true })
    .click();
  await expect(page).toHaveURL(/\/playground$/);
  await recovery
    .getByRole('button', { name: 'Simulate expiry', exact: true })
    .click();
  await expect(recovery.getByRole('alert')).toContainText(
    'This page has expired',
  );
  expect(dialogs).toHaveLength(2);
  expect(await page.evaluate(() => window.name)).toBe('expiry document');
});
