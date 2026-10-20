import { expect, test } from '@playwright/test';
import { islandId, targetsIsland } from './requests';

/**
 * Hold an intercepted request until the test explicitly allows it to proceed.
 */
function gate() {
  let release = () => {};
  const promise = new Promise<void>((resolve) => {
    release = resolve;
  });
  return { promise, release: () => release() };
}

test('a queued form accepts one submission and preserves edits made while waiting and saving', async ({
  page,
}) => {
  const toggle = gate();
  const toggleSeen = gate();
  const save = gate();
  const saveSeen = gate();
  const actions: unknown[] = [];
  let ownerId = '';
  await page.route('**/_interact/update', async (route) => {
    if (!targetsIsland(route.request(), ownerId)) {
      await route.continue();
      return;
    }
    const body: unknown = route.request().postDataJSON();
    if (typeof body !== 'object' || body === null || !('action' in body))
      throw new Error('Missing action.');
    actions.push(body.action);
    if (body.action === 'toggle') {
      toggleSeen.release();
      await toggle.promise;
    }
    if (body.action === 'add') {
      saveSeen.release();
      await save.promise;
    }
    await route.continue();
  });
  await page.goto('/playground');
  const board = page.getByRole('region', { name: 'Task board', exact: true });
  ownerId = await islandId(board);
  const input = board.getByLabel('Add a task', { exact: true });
  const button = board.getByRole('button', { name: 'Add task', exact: true });
  await input.fill('Initial draft');
  await board
    .getByRole('button', {
      name: 'Complete Try a conditional form',
      exact: true,
    })
    .click();
  await toggleSeen.promise;
  const form = board.locator('form');
  await form.evaluate((element) => {
    if (!(element instanceof HTMLFormElement)) throw new Error('Missing form.');
    element.requestSubmit();
    element.requestSubmit();
  });
  await expect(button).toBeDisabled();
  await expect(form).toHaveAttribute('aria-busy', 'true');
  await expect(input).toBeEditable();
  expect(actions).toEqual(['toggle']);
  await input.fill('Latest queued draft');
  toggle.release();
  await saveSeen.promise;
  await expect(button).toBeDisabled();
  await input.fill('Next unsaved draft');
  save.release();
  await expect(
    board.getByText('Latest queued draft', { exact: true }),
  ).toBeVisible();
  await expect(input).toHaveValue('Next unsaved draft');
  await expect(button).toBeEnabled();
  await expect(form).not.toHaveAttribute('aria-busy', 'true');
  expect(actions).toEqual(['toggle', 'add']);
});

test('row-specific loading is delayed and does not disable unrelated actions', async ({
  page,
}) => {
  await page.clock.install();
  const request = gate();
  const seen = gate();
  let ownerId = '';
  await page.route('**/_interact/update', async (route) => {
    if (!targetsIsland(route.request(), ownerId)) {
      await route.continue();
      return;
    }
    seen.release();
    await request.promise;
    await route.continue();
  });
  await page.goto('/playground');
  const board = page.getByRole('region', { name: 'Task board', exact: true });
  ownerId = await islandId(board);
  const row = board.locator('#demo-task-2');
  await row
    .getByRole('button', { name: 'Remove Try a conditional form', exact: true })
    .click();
  await seen.promise;
  await expect(
    row.getByRole('button', {
      name: 'Remove Try a conditional form',
      exact: true,
    }),
  ).toBeDisabled();
  await expect(
    board.getByRole('button', {
      name: 'Remove Build something with CakePHP',
      exact: true,
    }),
  ).toBeEnabled();
  await expect(row.getByText('Removing…')).toBeHidden();
  await page.clock.runFor(250);
  await expect(row.getByText('Removing…')).toBeVisible();
  await expect(row).toHaveClass(/task-pending/);
  await expect(
    board.locator('#demo-task-3').getByText('Removing…'),
  ).toBeHidden();
  request.release();
  await expect(row).toHaveCount(0);
  await expect(board.locator('.task-feedback:visible')).toHaveCount(0);
});

test('handwritten forms retain native validation and unlock after failure for an explicit retry', async ({
  page,
}) => {
  const request = gate();
  const seen = gate();
  let requests = 0;
  let ownerId = '';
  await page.route('**/_interact/update', async (route) => {
    if (!targetsIsland(route.request(), ownerId)) {
      await route.continue();
      return;
    }
    requests++;
    if (requests === 1) {
      seen.release();
      await request.promise;
      await route.fulfill({
        status: 500,
        contentType: 'application/json',
        body: JSON.stringify({
          version: 1,
          error: { code: 'server_error', message: 'Could not save.' },
        }),
      });
    } else await route.continue();
  });
  await page.goto('/playground');
  const region = page.getByRole('region', {
    name: 'Failure recovery',
    exact: true,
  });
  ownerId = await islandId(region);
  await region.locator('form').evaluate((form) => {
    form.outerHTML =
      '<form x-interact:submit="$server.call(\'save\')"><label>Plain draft<input required name="message" x-interact:model="message"></label><button>Plain save</button><button disabled>Unavailable</button></form>';
  });
  const input = region.getByLabel('Plain draft', { exact: true });
  const form = region.locator('form');
  await form.evaluate((element) => {
    if (!(element instanceof HTMLFormElement))
      throw new Error('Missing native form.');
    element.requestSubmit();
  });
  expect(requests).toBe(0);
  await input.fill('Native form draft');
  await input.press('Enter');
  await seen.promise;
  await expect(
    region.getByRole('button', { name: 'Plain save' }),
  ).toBeDisabled();
  await form.evaluate((element) => {
    if (!(element instanceof HTMLFormElement))
      throw new Error('Missing native form.');
    element.requestSubmit();
  });
  await input.fill('Newer retained draft');
  request.release();
  await expect(region.getByRole('alert')).toContainText('Could not save.');
  await expect(
    region.getByRole('button', { name: 'Plain save' }),
  ).toBeEnabled();
  await expect(
    region.getByRole('button', { name: 'Unavailable' }),
  ).toBeDisabled();
  await expect(input).toHaveValue('Newer retained draft');
  expect(requests).toBe(1);
  await region.getByRole('button', { name: 'Plain save' }).click();
  await expect(region.getByRole('status')).toHaveText('Accepted 1 times.');
  await expect(
    region.getByRole('button', { name: 'Accept draft' }),
  ).toBeEnabled();
  expect(requests).toBe(2);
});
