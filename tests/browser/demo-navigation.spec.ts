import { expect, test } from './fixtures';

test('desktop navigation stays beside the page and survives enhanced visits', async ({
  page,
}) => {
  await page.setViewportSize({ width: 1440, height: 900 });
  await page.goto('/navigation');
  const sidebar = page.getByRole('banner');
  const navigation = page.getByRole('navigation', { name: 'Demo pages' });
  const original = await sidebar.elementHandle();
  const sidebarBounds = await sidebar.boundingBox();
  const contentBounds = await page.getByRole('main').boundingBox();
  if (!sidebarBounds || !contentBounds) throw new Error('Missing demo layout.');
  expect(sidebarBounds.x + sidebarBounds.width).toBeLessThanOrEqual(
    contentBounds.x,
  );
  await expect(
    page.getByRole('button', { name: 'Menu', exact: true }),
  ).toBeHidden();
  await page.evaluate(() => window.scrollTo(0, 500));
  await expect.poll(async () => (await sidebar.boundingBox())?.y).toBe(0);
  await navigation
    .getByRole('link', { name: 'Renderless actions', exact: true })
    .click();
  await expect(page).toHaveURL('/renderless');
  await expect(page.getByRole('heading', { level: 1 })).toHaveText(
    'Renderless actions',
  );
  expect(await original?.evaluate((element) => element.isConnected)).toBe(true);
  await expect(navigation.locator('[aria-current="page"]')).toHaveCount(1);
  await expect(
    navigation.getByRole('link', { name: 'Renderless actions', exact: true }),
  ).toHaveAttribute('aria-current', 'page');
});

test('mobile menu supports keyboard dismissal and closes after enhanced navigation', async ({
  page,
}) => {
  const errors: string[] = [];
  page.on('pageerror', (error) => errors.push(error.message));
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('/navigation');
  const menu = page.getByRole('button', { name: 'Menu', exact: true });
  const navigation = page.getByRole('navigation', { name: 'Demo pages' });
  await expect(menu).toHaveAttribute('aria-expanded', 'false');
  await expect(navigation).toBeHidden();
  await menu.click();
  await expect(menu).toHaveAttribute('aria-expanded', 'true');
  const link = navigation.getByRole('link', {
    name: 'Renderless actions',
    exact: true,
  });
  await link.focus();
  await link.press('Escape');
  await expect(menu).toBeFocused();
  await expect(navigation).toBeHidden();
  await menu.click();
  await link.click();
  await expect(page).toHaveURL('/renderless');
  await expect(menu).toHaveAttribute('aria-expanded', 'false');
  await expect(navigation).toBeHidden();
  await expect(page.getByLabel('Draft title')).toBeVisible();
  expect(
    await page.evaluate(() => document.documentElement.scrollWidth),
  ).toBeLessThanOrEqual(390);
  // The same retained navigation remains available for the next visit.
  await menu.click();
  await expect(
    navigation.getByRole('link', { name: 'Renderless actions', exact: true }),
  ).toHaveAttribute('aria-current', 'page');
  await navigation
    .getByRole('link', { name: 'Article form', exact: true })
    .click();
  await expect(page).toHaveURL('/forms');
  await expect(menu).toHaveAttribute('aria-expanded', 'false');
  expect(errors).toEqual([]);
});
