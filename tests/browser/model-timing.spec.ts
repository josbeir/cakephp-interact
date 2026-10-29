import { expect, test } from './fixtures';
import { islandId, targetsIsland } from './requests';

for (const control of ['select', 'checkbox', 'radio']) {
  test(`live ${control} updates the island without advancing the debounce clock`, async ({
    page,
  }) => {
    await page.clock.install({ time: new Date('2026-10-29T12:00:00Z') });
    await page.clock.pauseAt(new Date('2026-10-29T12:00:01Z'));
    const errors: string[] = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await page.goto('/playground');
    const planner = page.getByRole('region', {
      name: 'Workshop planner',
      exact: true,
    });
    const id = await islandId(planner);
    let requests = 0;
    page.on('request', (request) => {
      if (targetsIsland(request, id)) requests++;
    });
    if (control === 'radio') {
      await planner.evaluate((element) => {
        element.insertAdjacentHTML(
          'beforeend',
          '<label>Studio radio<input type="radio" name="timing-format" value="studio" x-interact:model.live="format"></label>',
        );
      });
      await expect(planner.getByLabel('Studio radio')).toBeVisible();
    }
    const response = page.waitForResponse((reply) =>
      targetsIsland(reply.request(), id),
    );
    if (control === 'select')
      await planner.getByLabel('Workshop format').selectOption('studio');
    else if (control === 'checkbox')
      await planner.getByLabel(/^Book for a company/).check();
    else await planner.getByLabel('Studio radio').check();
    const accepted = await response;
    expect(accepted.ok()).toBe(true);
    expect(accepted.request().postDataJSON()).toMatchObject({
      action: null,
      updates:
        control === 'checkbox' ? { business: true } : { format: 'studio' },
    });
    if (control === 'checkbox')
      await expect(planner.getByLabel('Company name')).toBeVisible();
    else
      await expect(planner.getByLabel('Workshop format')).toHaveValue('studio');
    await page.clock.runFor(1000);
    expect(requests).toBe(1);
    expect(errors).toEqual([]);
  });
}

test('an explicit selection debounce restarts and updates the island once', async ({
  page,
}) => {
  await page.clock.install({ time: new Date('2026-10-29T12:00:00Z') });
  await page.clock.pauseAt(new Date('2026-10-29T12:00:01Z'));
  await page.goto('/playground');
  const planner = page.getByRole('region', {
    name: 'Workshop planner',
    exact: true,
  });
  const id = await islandId(planner);
  let requests = 0;
  page.on('request', (request) => {
    if (targetsIsland(request, id)) requests++;
  });
  await planner.evaluate((element) => {
    element.insertAdjacentHTML(
      'beforeend',
      '<label>Debounced format<select x-interact:model.live.debounce.400ms="format"><option value="remote">Online</option><option value="studio">Studio</option></select></label>',
    );
  });
  const format = planner.getByLabel('Debounced format');
  await expect(format).toHaveValue('remote');
  await format.selectOption('studio');
  await page.clock.runFor(200);
  await format.selectOption('remote');
  await page.clock.runFor(200);
  await format.selectOption('studio');
  await page.clock.runFor(399);
  expect(requests).toBe(0);
  const response = page.waitForResponse((reply) =>
    targetsIsland(reply.request(), id),
  );
  await page.clock.runFor(1);
  const accepted = await response;
  expect(accepted.ok()).toBe(true);
  expect(accepted.request().postDataJSON()).toMatchObject({
    updates: { format: 'studio' },
  });
  await expect(planner.getByLabel('Workshop format')).toHaveValue('studio');
  await page.clock.runFor(1000);
  expect(requests).toBe(1);
});
