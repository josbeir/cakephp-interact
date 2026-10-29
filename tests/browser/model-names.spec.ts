import { expect, test } from './fixtures';
import { islandId, targetsIsland } from './requests';

test('a shorthand control overrides form timing, uses its relative name, and preserves a newer draft', async ({
  page,
}) => {
  await page.goto('/forms');
  const region = page.getByRole('region', {
    name: 'FormHelper article',
    exact: true,
  });
  const owner = region.locator('[data-interact-id]').first();
  const id = await islandId(owner);
  const title = region.getByLabel('Title', { exact: true });
  await title.evaluate((element) =>
    element.setAttribute('x-interact:model.blur', ''),
  );
  await title.fill('Shorthand title');
  let release = () => {};
  const held = new Promise<void>((resolve) => {
    release = resolve;
  });
  let received = () => {};
  const started = new Promise<void>((resolve) => {
    received = resolve;
  });
  await page.route('**/_interact/update', async (route) => {
    if (!targetsIsland(route.request(), id)) return route.continue();
    const response = await route.fetch();
    received();
    await held;
    await route.fulfill({ response });
  });
  const response = page.waitForResponse((reply) =>
    targetsIsland(reply.request(), id),
  );
  await title.blur();
  await started;
  await title.fill('Newer local title');
  release();
  const accepted = await response;
  expect(accepted.ok()).toBe(true);
  expect(accepted.request().postDataJSON()).toMatchObject({
    action: null,
    updates: { data: { title: 'Shorthand title' } },
  });
  await expect(title).toHaveValue('Newer local title');
  await expect(title).toBeFocused();
});

test('a shorthand external control follows its native form and bracket name', async ({
  page,
}) => {
  await page.goto('/forms');
  const region = page.getByRole('region', {
    name: 'FormHelper article',
    exact: true,
  });
  const owner = region.locator('[data-interact-id]').first();
  const id = await islandId(owner);
  await owner.evaluate((element) => {
    element.insertAdjacentHTML(
      'beforeend',
      '<label>External comment<textarea name="comments[0][body]" form="article-draft" x-interact:model.blur></textarea></label>',
    );
  });
  const comment = region.getByLabel('External comment');
  await comment.fill('External shorthand comment');
  const response = page.waitForResponse((reply) =>
    targetsIsland(reply.request(), id),
  );
  await comment.blur();
  const accepted = await response;
  expect(accepted.ok()).toBe(true);
  expect(accepted.request().postDataJSON()).toMatchObject({
    updates: { data: { comments: [{ body: 'External shorthand comment' }] } },
  });
  await expect(
    region.getByLabel('Comment 1 text', { exact: true }),
  ).toHaveValue('External shorthand comment');
});

test('renaming a shorthand cancels its old debounce and switches to the new field', async ({
  page,
}) => {
  await page.clock.install({ time: new Date('2026-10-29T12:00:00Z') });
  await page.clock.pauseAt(new Date('2026-10-29T12:00:01Z'));
  await page.goto('/search');
  const region = page.getByRole('region', {
    name: 'Resource library',
    exact: true,
  });
  const search = page.getByLabel('Search resources');
  const id = await islandId(search);
  await region
    .getByLabel('Reading notes')
    .evaluate((element) => element.remove());
  let requests = 0;
  page.on('request', (request) => {
    if (targetsIsland(request, id)) requests++;
  });
  await search.fill('unsent search');
  await search.evaluate((element) => element.setAttribute('name', 'notes'));
  await expect(search).toHaveValue('');
  await page.clock.runFor(400);
  expect(requests).toBe(0);
  await search.fill('renamed draft');
  const response = page.waitForResponse((reply) =>
    targetsIsland(reply.request(), id),
  );
  await page.clock.runFor(300);
  const accepted = await response;
  expect(accepted.ok()).toBe(true);
  expect(accepted.request().postDataJSON()).toMatchObject({
    updates: { search: 'unsent search', notes: 'renamed draft' },
  });
  await expect(region.getByLabel('Search resources')).toHaveValue(
    'unsent search',
  );
  await expect(region.getByLabel('Reading notes')).toHaveValue('renamed draft');
});
