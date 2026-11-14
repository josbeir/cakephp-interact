import { expect, test } from './fixtures';
import { islandId, targetsIsland } from './requests';

test('a mounted island continues updating after a day without refreshing its snapshot', async ({
  page,
}) => {
  await page.clock.install();
  await page.goto('/playground');
  const counter = page.getByRole('region', {
    name: 'First counter',
    exact: true,
  });
  const id = await islandId(counter);
  const root = page.locator(`[data-interact-id="${id}"]`);
  const snapshot = await root.getAttribute('data-interact-snapshot');
  if (!snapshot) throw new Error('Missing initial snapshot.');
  const envelope: unknown = JSON.parse(
    Buffer.from(snapshot.split('.')[0] ?? '', 'base64url').toString(),
  );
  expect(envelope).toMatchObject({ version: 1, expires: null });
  let requests = 0;
  page.on('request', (request) => {
    if (targetsIsland(request, id)) requests++;
  });
  await page.clock.fastForward(86_400_000);
  await expect(root).toHaveAttribute('data-interact-snapshot', snapshot);
  expect(requests).toBe(0);
  await counter.getByRole('button', { name: 'Add five' }).click();
  await expect(counter.locator('[aria-live="polite"]')).toHaveText('Count: 5');
  await expect(counter.getByRole('alert')).toHaveCount(0);
  expect(requests).toBe(1);
});

test('a manual island can initialize after its placeholder has been idle for a day', async ({
  page,
}) => {
  await page.clock.install();
  await page.goto('/loading');
  const manual = page
    .getByRole('region', { name: 'Manual report', exact: true })
    .locator('[data-interact-id]')
    .first();
  await expect(manual).toHaveAttribute('data-interact-loading', 'manual');
  const snapshot = await manual.getAttribute('data-interact-snapshot');
  if (!snapshot) throw new Error('Missing pending snapshot.');
  const envelope: unknown = JSON.parse(
    Buffer.from(snapshot.split('.')[0] ?? '', 'base64url').toString(),
  );
  expect(envelope).toMatchObject({ version: 1, expires: null });
  await page.clock.fastForward(86_400_000);
  await expect(manual).toHaveAttribute('data-interact-snapshot', snapshot);
  await manual
    .getByRole('button', { name: 'Load report', exact: true })
    .click();
  await expect(manual).not.toHaveAttribute('data-interact-loading');
  await expect(manual).toContainText('Mounted 1 time');
});
