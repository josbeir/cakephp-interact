import { expect, test } from '@playwright/test';

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
  const response = page.waitForResponse('**/_interact/update');
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
  page.on('request', (request) => {
    if (request.url().endsWith('/_interact/update')) requests++;
  });
  await page.goto('/playground');
  const region = page.getByRole('region', {
    name: 'Failure recovery',
    exact: true,
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
  const response = page.waitForResponse('**/_interact/update');
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
