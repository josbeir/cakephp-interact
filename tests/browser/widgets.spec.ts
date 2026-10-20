import { expect, test } from '@playwright/test';
import { islandId, targetsIsland } from './requests';

test('PHP updates both panels in the response while the ignored DOM remains unchanged until recreated', async ({
  page,
}) => {
  const errors: string[] = [];
  page.on('pageerror', (error) => errors.push(error.message));
  await page.goto('/playground');
  const region = page.getByRole('region', {
    name: 'Morph protection',
    exact: true,
  });
  const normal = region.getByLabel('Normal region', { exact: true });
  const ignored = region.getByLabel('Ignored region', { exact: true });
  const id = await islandId(region);
  for (let count = 1; count <= 3; count++) {
    const response = page.waitForResponse((reply) =>
      targetsIsland(reply.request(), id),
    );
    await region
      .getByRole('button', { name: 'Update from PHP', exact: true })
      .click();
    const payload: unknown = await (await response).json();
    if (
      typeof payload !== 'object' ||
      payload === null ||
      !('html' in payload) ||
      typeof payload.html !== 'string'
    )
      throw new Error('Missing server render.');
    expect(
      payload.html.match(new RegExp(`<strong>${count}</strong>`, 'g')),
    ).toHaveLength(2);
    await expect(
      normal.getByText(`Render count: ${count}`, { exact: true }),
    ).toBeVisible();
    await expect(
      ignored.getByText('Render count: 0', { exact: true }),
    ).toBeVisible();
  }
  await expect(ignored).toHaveClass(/swatch-mint/);
  await region
    .getByRole('button', { name: 'Update from PHP', exact: true })
    .click();
  await expect(normal).toHaveClass(/swatch-violet/);
  await expect(ignored).toHaveClass(/swatch-mint/);
  await region
    .getByRole('button', { name: 'Recreate ignored region', exact: true })
    .click();
  await expect(ignored).toHaveAttribute('id', 'protected-panel-1');
  await expect(
    ignored.getByText('Render count: 4', { exact: true }),
  ).toBeVisible();
  await expect(ignored).toHaveClass(/swatch-violet/);
  expect(errors).toEqual([]);
});

test('a real select widget supports validation, PHP reset, preservation, and removal cleanup under CSP', async ({
  page,
}) => {
  const errors: string[] = [];
  page.on('pageerror', (error) => errors.push(error.message));
  await page.goto('/playground');
  const region = page.getByRole('region', {
    name: 'JavaScript select widget',
    exact: true,
  });
  const id = await islandId(region);
  let requests = 0;
  page.on('request', (request) => {
    if (targetsIsland(request, id)) requests++;
  });
  const select = region.getByRole('combobox', {
    name: 'Search countries',
    exact: true,
  });
  await expect(select).toBeVisible();
  await expect(region.locator('.ts-wrapper')).toHaveCount(1);
  expect(requests).toBe(0);
  await region
    .getByRole('button', { name: 'Confirm country', exact: true })
    .click();
  await expect(
    region
      .getByRole('alert')
      .filter({ hasText: 'Choose a country from the list.' }),
  ).toBeVisible();
  await expect(select).toHaveAttribute('aria-invalid', 'true');
  await select.fill('Belg');
  await expect(region.getByRole('listbox').getByRole('option')).toHaveCount(1);
  await region
    .getByRole('listbox')
    .getByRole('option', { name: 'Belgium', exact: true })
    .click();
  await expect(
    region
      .getByRole('alert')
      .filter({ hasText: 'Choose a country from the list.' }),
  ).toHaveCount(0);
  await region
    .getByRole('button', { name: 'Confirm country', exact: true })
    .click();
  await expect(
    region.getByRole('status').filter({ hasText: 'Confirmed: Belgium.' }),
  ).toBeVisible();
  await region.locator('#widget-country').evaluate((element) => {
    element.setAttribute('data-instance-probe', 'survives');
    const owner = element.closest('section');
    const widget: unknown = Reflect.get(element, 'tomselect');
    if (
      typeof widget !== 'object' ||
      widget === null ||
      !('on' in widget) ||
      typeof widget.on !== 'function'
    )
      throw new Error('Missing Tom Select instance.');
    Reflect.apply(widget.on, widget, [
      'destroy',
      () => owner?.setAttribute('data-widget-destroyed', 'true'),
    ]);
  });
  await region
    .getByRole('button', { name: 'Refresh surrounding content', exact: true })
    .click();
  await expect(
    region
      .getByRole('status')
      .filter({ hasText: 'Surrounding content refreshed 1 times.' }),
  ).toBeVisible();
  await expect(region.locator('#widget-country')).toHaveAttribute(
    'data-instance-probe',
    'survives',
  );
  await expect(region.locator('.ts-wrapper')).toHaveCount(1);
  await expect(region.locator('.ts-control .item')).toHaveText('Belgium');
  const beforeReset = requests;
  await region
    .getByRole('button', { name: 'Reset from PHP', exact: true })
    .click();
  await expect(region.locator('.ts-control .item')).toHaveCount(0);
  await expect(region.locator('#widget-country')).toHaveValue('');
  await expect(region.locator('[x-interact\\:model\\.change]')).toHaveCount(1);
  expect(requests).toBe(beforeReset + 1);
  await region
    .getByRole('button', { name: 'Remove widget', exact: true })
    .click();
  await expect(region.locator('.ts-wrapper')).toHaveCount(0);
  await expect(region).toHaveAttribute('data-widget-destroyed', 'true');
  await region
    .getByRole('button', { name: 'Restore widget', exact: true })
    .click();
  await expect(select).toBeVisible();
  await expect(region.locator('.ts-wrapper')).toHaveCount(1);
  await select.fill('Nether');
  await expect(region.getByRole('listbox').getByRole('option')).toHaveCount(1);
  await region
    .getByRole('listbox')
    .getByRole('option', { name: 'Netherlands', exact: true })
    .click();
  await expect(region.locator('#widget-country')).toHaveValue('NL');
  await region
    .getByRole('button', { name: 'Confirm country', exact: true })
    .click();
  await expect(
    region.getByRole('status').filter({ hasText: 'Confirmed: Netherlands.' }),
  ).toBeVisible();
  expect(errors).toEqual([]);
});
