import { expect, test } from './fixtures';
import { islandId, targetsIsland } from './requests';

test('named event listeners isolate islands and clean up conditional and morphed elements', async ({
  page,
}) => {
  const errors: string[] = [];
  page.on('pageerror', (error) => errors.push(error.message));
  await page.goto('/playground');
  const first = page.getByRole('region', {
    name: 'First counter',
    exact: true,
  });
  const second = page.getByRole('region', {
    name: 'Second counter',
    exact: true,
  });
  for (const region of [first, second]) {
    await region.evaluate((element) => {
      element.insertAdjacentHTML(
        'beforeend',
        `
        <section x-data="{ count: 0, visible: true }">
          <button @click="visible = !visible">Toggle event listener</button>
          <template x-if="visible">
            <span x-interact:on:notification="count = count + $event.detail.amount"></span>
          </template>
          <output aria-label="Received notifications" x-text="count"></output>
        </section>
      `,
      );
    });
    await expect(region.getByLabel('Received notifications')).toHaveText('0');
  }
  const deliver = async (source: 'push' | 'stream') => {
    await first.evaluate((element, source) => {
      const root = element.closest('[data-interact-id]');
      if (!root) throw new Error('Missing island root.');
      root.dispatchEvent(
        new CustomEvent(`interact:${source}`, {
          bubbles: true,
          detail:
            source === 'push'
              ? {
                  id: 'notification-1',
                  topic: 'inbox',
                  event: 'notification',
                  detail: { amount: 1 },
                }
              : { name: 'notification', detail: { amount: 1 } },
        }),
      );
    }, source);
  };
  await deliver('push');
  await deliver('stream');
  await expect(first.getByLabel('Received notifications')).toHaveText('2');
  await expect(second.getByLabel('Received notifications')).toHaveText('0');
  await first.getByRole('button', { name: 'Toggle event listener' }).click();
  await expect(first.locator('[x-interact\\:on\\:notification]')).toHaveCount(
    0,
  );
  await deliver('push');
  await expect(first.getByLabel('Received notifications')).toHaveText('2');
  await first.getByRole('button', { name: 'Toggle event listener' }).click();
  await expect(first.locator('[x-interact\\:on\\:notification]')).toHaveCount(
    1,
  );
  await deliver('stream');
  await expect(first.getByLabel('Received notifications')).toHaveText('3');
  await first.getByRole('button', { name: 'Add five' }).click();
  await expect(first.locator('.counter-value')).toHaveText('Count: 5');
  await expect(first.getByLabel('Received notifications')).toHaveCount(0);
  await deliver('push');
  await expect(second.getByLabel('Received notifications')).toHaveText('0');
  expect(errors).toEqual([]);
});

test('submission expressions use local Alpine scope and the native event with typed arguments', async ({
  page,
}) => {
  const errors: string[] = [];
  page.on('pageerror', (error) => errors.push(error.message));
  await page.goto('/playground');
  const counter = page.getByRole('region', {
    name: 'First counter',
    exact: true,
  });
  await counter.evaluate((element) => {
    element.insertAdjacentHTML(
      'beforeend',
      '<form x-data="{ amount: 5 }" x-interact:submit="$server.call(\'adjust\', $event.type === \'submit\' ? amount : 0)"><button>Submit scoped action</button></form>',
    );
  });
  const id = await islandId(counter);
  const response = page.waitForResponse((reply) =>
    targetsIsland(reply.request(), id),
  );
  await counter.getByRole('button', { name: 'Submit scoped action' }).click();
  const accepted = await response;
  expect(accepted.ok()).toBe(true);
  expect(accepted.request().postDataJSON()).toMatchObject({
    action: 'adjust',
    args: [5],
  });
  await expect(counter.locator('.counter-value')).toHaveText('Count: 5');
  expect(errors).toEqual([]);
});

test('conditional models clean up debounce work, retain drafts, and rebind changed modifiers', async ({
  page,
}) => {
  await page.clock.install();
  let requests = 0;
  const errors: string[] = [];
  page.on('pageerror', (error) => errors.push(error.message));
  await page.goto('/playground');
  const region = page.getByRole('region', {
    name: 'Failure recovery',
    exact: true,
  });
  const id = await islandId(region);
  page.on('request', (request) => {
    if (targetsIsland(request, id)) requests++;
  });
  await region.evaluate((element) => {
    element.querySelector('form')?.remove();
    element.insertAdjacentHTML(
      'beforeend',
      '<div x-data="{ visible: true }"><button @click="visible = !visible">Toggle optional draft</button><template x-if="visible"><label>Optional draft<input x-interact:model.live.debounce.5000ms="message"></label></template></div>',
    );
  });
  const draft = region.getByLabel('Optional draft');
  await draft.fill('Retain this draft');
  await region.getByRole('button', { name: 'Toggle optional draft' }).click();
  await expect(draft).toHaveCount(0);
  await page.clock.runFor(6000);
  expect(requests).toBe(0);
  await region.getByRole('button', { name: 'Toggle optional draft' }).click();
  await expect(draft).toHaveValue('Retain this draft');
  await draft.evaluate((element) => {
    element.removeAttribute('x-interact:model.live.debounce.5000ms');
    element.setAttribute('x-interact:model.blur', 'message');
  });
  await draft.fill('Submit on blur');
  const response = page.waitForResponse((reply) =>
    targetsIsland(reply.request(), id),
  );
  await draft.blur();
  const accepted = await response;
  expect(accepted.ok()).toBe(true);
  expect(accepted.request().postDataJSON()).toMatchObject({
    action: null,
    updates: { message: 'Submit on blur' },
  });
  await expect(
    region.getByLabel('Recovery draft', { exact: true }),
  ).toHaveValue('Submit on blur');
  expect(requests).toBe(1);
  expect(errors).toEqual([]);
});
