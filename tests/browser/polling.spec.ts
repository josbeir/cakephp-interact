import { expect, test } from './fixtures';
import type { Locator, Page } from '@playwright/test';
import { islandId, targetsIsland } from './requests';

test.beforeEach(async ({ page }) => {
  await page.clock.install({ time: new Date('2026-10-20T12:00:00Z') });
  await page.clock.pauseAt(new Date('2026-10-20T12:00:01Z'));
});

async function advancePoll(page: Page, region: Locator, duration = 5000) {
  const id = await islandId(region);
  const response = page.waitForResponse((reply) =>
    targetsIsland(reply.request(), id),
  );
  await page.clock.runFor(duration);
  const accepted = await response;
  expect(accepted.status()).toBe(200);
  await expect(region.locator('xpath=..')).not.toHaveAttribute(
    'aria-busy',
    'true',
  );
  return accepted;
}

test('clock polling refreshes server data and preserves a local pause through morphing', async ({
  page,
}) => {
  const errors: string[] = [];
  page.on('pageerror', (error) => errors.push(error.message));
  await page.goto('/playground');
  const clock = page.getByRole('region', { name: 'Server clock', exact: true });
  const id = await islandId(clock);
  let requests = 0;
  page.on('request', (request) => {
    if (targetsIsland(request, id)) requests++;
  });
  await page.clock.runFor(4999);
  expect(requests).toBe(0);
  const accepted = await advancePoll(page, clock, 1);
  expect(accepted.request().postDataJSON()).toMatchObject({
    action: null,
    args: [],
    updates: {},
  });
  await expect(clock.locator('time')).toHaveAttribute(
    'datetime',
    /^\d{4}-\d{2}-\d{2}T/,
  );
  const enabled = clock.getByRole('checkbox', {
    name: 'Automatically refresh the clock',
  });
  await enabled.uncheck();
  await page.clock.runFor(10000);
  expect(requests).toBe(1);
  const manual = page.waitForResponse((reply) =>
    targetsIsland(reply.request(), id),
  );
  await clock.getByRole('button', { name: 'Refresh clock now' }).click();
  expect((await manual).status()).toBe(200);
  await expect(enabled).not.toBeChecked();
  await enabled.check();
  await advancePoll(page, clock);
  expect(requests).toBe(3);
  expect(errors).toEqual([]);
});

test('conditional PHP actions stop requesting at completion and resume after restart', async ({
  page,
}) => {
  await page.goto('/playground');
  const job = page.getByRole('region', {
    name: 'Simulated job progress',
    exact: true,
  });
  const id = await islandId(job);
  let requests = 0;
  page.on('request', (request) => {
    if (targetsIsland(request, id)) requests++;
  });
  for (const progress of [25, 50, 75, 100]) {
    const response = await advancePoll(page, job);
    expect(response.request().postDataJSON()).toMatchObject({
      action: 'refreshStatus',
      args: [],
    });
    await expect(job.getByRole('progressbar')).toHaveAttribute(
      'value',
      String(progress),
    );
  }
  await expect(job.getByRole('status')).toHaveText('Simulated job complete.');
  await page.clock.runFor(20000);
  expect(requests).toBe(4);
  const restarted = page.waitForResponse((reply) =>
    targetsIsland(reply.request(), id),
  );
  await job.getByRole('button', { name: 'Restart simulated job' }).click();
  expect((await restarted).status()).toBe(200);
  await expect(job.getByRole('progressbar')).toHaveAttribute('value', '0');
  await advancePoll(page, job);
  await expect(job.getByRole('progressbar')).toHaveAttribute('value', '25');
  expect(requests).toBe(6);
});

test('a local pause button stops job requests and resumes without overriding PHP completion', async ({
  page,
}) => {
  await page.goto('/playground');
  const job = page.getByRole('region', {
    name: 'Simulated job progress',
    exact: true,
  });
  const id = await islandId(job);
  let requests = 0;
  page.on('request', (request) => {
    if (targetsIsland(request, id)) requests++;
  });

  await job.getByRole('button', { name: 'Pause polling', exact: true }).click();
  const resume = job.getByRole('button', {
    name: 'Resume polling',
    exact: true,
  });
  await expect(resume).toHaveAttribute('aria-pressed', 'true');
  await page.clock.runFor(15000);
  expect(requests).toBe(0);

  await resume.click();
  await expect(
    job.getByRole('button', { name: 'Pause polling', exact: true }),
  ).toHaveAttribute('aria-pressed', 'false');
  for (const progress of [25, 50, 75, 100]) {
    await advancePoll(page, job);
    await expect(job.getByRole('progressbar')).toHaveAttribute(
      'value',
      String(progress),
    );
  }
  await expect(
    job.getByRole('button', { name: 'Pause polling', exact: true }),
  ).toBeDisabled();
  await page.clock.runFor(15000);
  expect(requests).toBe(4);
});

test('polling preserves deferred drafts and entity errors while parent refreshes retain child ownership', async ({
  page,
}) => {
  await page.goto('/demo/polling');
  const editor = page.getByRole('region', {
    name: 'Polling article editor',
    exact: true,
  });
  const parent = page.getByRole('region', {
    name: 'Polling article list',
    exact: true,
  });
  const editorId = await islandId(editor);
  const parentId = await islandId(parent);
  const ids = await page
    .locator('[id]')
    .evaluateAll((elements) => elements.map((element) => element.id));
  expect(new Set(ids).size).toBe(ids.length);
  expect(
    await editor
      .locator('form')
      .evaluate((form: HTMLFormElement) =>
        [...form.querySelectorAll('input,button')].every(
          (control) =>
            (control instanceof HTMLInputElement ||
              control instanceof HTMLButtonElement) &&
            control.form === form,
        ),
      ),
  ).toBe(true);
  let editorRequests = 0;
  let parentRequests = 0;
  page.on('request', (request) => {
    if (targetsIsland(request, editorId)) editorRequests++;
    if (targetsIsland(request, parentId)) parentRequests++;
  });
  const title = editor.getByLabel('Article title');
  await title.fill('Local deferred draft');
  const childTitle = parent
    .getByRole('article', { name: 'Article 2', exact: true })
    .getByLabel('Article title');
  await childTitle.fill('Keep this child draft');
  await advancePoll(page, parent, 1000);
  await advancePoll(page, parent, 1000);
  expect(editorRequests).toBe(0);
  expect(parentRequests).toBe(2);
  await expect(childTitle).toHaveValue('Keep this child draft');
  await expect(childTitle).toBeFocused();

  await title.fill('');
  await editor.locator('form').evaluate((form: HTMLFormElement) => {
    form.noValidate = true;
  });
  const validation = page.waitForResponse((reply) =>
    targetsIsland(reply.request(), editorId),
  );
  await editor.getByRole('button', { name: 'Save article' }).click();
  expect((await validation).status()).toBe(422);
  await expect(title).toHaveAccessibleDescription('A title is required.');
  // Wait for the parent's morph before clicking within the adjacent editor.
  await advancePoll(page, parent, 3000);
  expect(editorRequests).toBe(1);
  expect(parentRequests).toBe(3);
  await expect(
    editor.getByRole('alert').filter({ hasText: 'A title is required.' }),
  ).toBeVisible();
  await title.fill('Alpine patterns');
  const saved = page.waitForResponse((reply) =>
    targetsIsland(reply.request(), editorId),
  );
  await editor.getByRole('button', { name: 'Save article' }).click();
  expect((await saved).status()).toBe(200);
  await expect(editor.getByRole('status')).toHaveText('Saved.');
  await advancePoll(page, editor, 1000);
  expect(editorRequests).toBe(3);
});

test('slow polling never accumulates requests and keeps newer typing and selection', async ({
  page,
}) => {
  await page.goto('/demo/polling');
  const editor = page.getByRole('region', {
    name: 'Polling article editor',
    exact: true,
  });
  const id = await islandId(editor);
  let release = () => {};
  const held = new Promise<void>((resolve) => {
    release = resolve;
  });
  let requests = 0;
  await page.route('**/_interact/update', async (route) => {
    if (targetsIsland(route.request(), id)) {
      requests++;
      await held;
    }
    await route.continue();
  });
  const requested = page.waitForRequest((request) =>
    targetsIsland(request, id),
  );
  await page.clock.runFor(1000);
  await requested;
  const title = editor.getByLabel('Article title');
  await title.fill('Typed during refresh');
  await title.evaluate((input: HTMLInputElement) =>
    input.setSelectionRange(2, 7),
  );
  await page.clock.runFor(5000);
  expect(requests).toBe(1);
  const completed = page.waitForResponse((reply) =>
    targetsIsland(reply.request(), id),
  );
  release();
  expect((await completed).status()).toBe(200);
  await expect(editor.locator('xpath=..')).not.toHaveAttribute(
    'aria-busy',
    'true',
  );
  await expect(title).toHaveValue('Typed during refresh');
  await expect(title).toBeFocused();
  expect(
    await title.evaluate((input: HTMLInputElement) => [
      input.selectionStart,
      input.selectionEnd,
    ]),
  ).toEqual([2, 7]);
  await page.clock.runFor(3000);
  expect(requests).toBe(1);
});

test('request failures stop automatic polling until explicit recovery succeeds', async ({
  page,
}) => {
  await page.goto('/demo/polling');
  const editor = page.getByRole('region', {
    name: 'Polling article editor',
    exact: true,
  });
  const id = await islandId(editor);
  let requests = 0;
  await page.route('**/_interact/update', async (route) => {
    if (targetsIsland(route.request(), id)) {
      requests++;
      if (requests === 1) {
        await route.abort('failed');
        return;
      }
    }
    await route.continue();
  });
  await page.clock.runFor(1000);
  await expect(editor.locator('.request-error')).toBeVisible();
  await page.clock.runFor(10000);
  expect(requests).toBe(1);
  const recovery = page.waitForResponse((reply) =>
    targetsIsland(reply.request(), id),
  );
  await editor.getByRole('button', { name: 'Save article' }).click();
  expect((await recovery).status()).toBe(200);
  await expect(editor.locator('.request-error')).toBeHidden();
  await advancePoll(page, editor, 1000);
  expect(requests).toBe(3);
});

test('hidden tabs suspend timers and viewport-only polling follows the declaration element', async ({
  page,
}) => {
  await page.goto('/playground');
  const clock = page.getByRole('region', { name: 'Server clock', exact: true });
  const id = await islandId(clock);
  let requests = 0;
  page.on('request', (request) => {
    if (targetsIsland(request, id)) requests++;
  });
  await page.evaluate(() => {
    Object.defineProperty(document, 'visibilityState', {
      configurable: true,
      get: () => 'hidden',
    });
    document.dispatchEvent(new Event('visibilitychange'));
  });
  await page.clock.runFor(30000);
  expect(requests).toBe(0);
  await page.evaluate(() => {
    Object.defineProperty(document, 'visibilityState', {
      configurable: true,
      get: () => 'visible',
    });
    document.dispatchEvent(new Event('visibilitychange'));
  });
  await page.clock.runFor(4999);
  expect(requests).toBe(0);
  await advancePoll(page, clock, 1);
  await clock.evaluate((element) => {
    const expression = element.getAttribute('x-interact:poll.5s') ?? '';
    element.removeAttribute('x-interact:poll.5s');
    element.setAttribute('x-interact:poll.5s.visible', expression);
  });
  await page.evaluate(() => window.scrollTo(0, 0));
  await expect(clock).not.toBeInViewport();
  await page.clock.runFor(10000);
  expect(requests).toBe(1);
  await clock.scrollIntoViewIfNeeded();
  await expect(clock).toBeInViewport();
  await page.clock.runFor(100);
  await advancePoll(page, clock);
  expect(requests).toBe(2);
});

test('removing an island releases its polling declaration', async ({
  page,
}) => {
  await page.goto('/playground');
  const clock = page.getByRole('region', { name: 'Server clock', exact: true });
  const id = await islandId(clock);
  let requests = 0;
  page.on('request', (request) => {
    if (targetsIsland(request, id)) requests++;
  });
  await advancePoll(page, clock);
  await clock.evaluate((element) =>
    element.closest('[data-interact-id]')?.remove(),
  );
  await expect(clock).toHaveCount(0);
  await page.clock.runFor(30000);
  expect(requests).toBe(1);
});
