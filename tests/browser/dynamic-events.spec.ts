import type { Locator } from '@playwright/test';
import { expect, test } from './fixtures';
import { islandId, targetsIsland } from './requests';

/** Restore worker-owned article fixtures after a scenario mutates persisted titles. */
async function restoreTitle(editor: Locator, title: string): Promise<void> {
  const id = await islandId(editor);
  const restored = editor
    .page()
    .waitForResponse((response) => targetsIsland(response.request(), id));
  await editor.getByLabel('Article title').fill(title);
  await editor.getByRole('button', { name: 'Save article' }).click();
  await restored;
}

for (const distribution of ['Composer', 'Application Alpine']) {
  test.describe(distribution, () => {
    test.beforeEach(async ({ page }) => {
      if (distribution === 'Application Alpine') {
        await page.route('**/interact/js/runtime.js', (route) =>
          route.fulfill({
            path: 'tests/test_app/webroot/js/configuration.js',
            contentType: 'text/javascript',
          }),
        );
      }
    });

    test('a scoped save refreshes only the matching article card and keeps independent drafts', async ({
      page,
    }) => {
      await page.goto('/events');
      const selected = page.getByRole('region', {
        name: 'Selected article',
        exact: true,
      });
      const other = page.getByRole('region', {
        name: 'Article 2',
        exact: true,
      });
      const selectedId = await islandId(selected);
      const otherId = await islandId(other);
      const received = { selected: 0, other: 0 };
      page.on('request', (request) => {
        if (targetsIsland(request, selectedId)) received.selected++;
        if (targetsIsland(request, otherId)) received.other++;
      });
      await selected.getByLabel('Local notes').fill('Keep selected notes');
      await other.getByLabel('Local notes').fill('Keep unrelated notes');
      const editor = page.getByRole('article', {
        name: 'Article 1',
        exact: true,
      });
      const originalTitle = await editor
        .getByLabel('Article title')
        .inputValue();
      const secondEditor = page.getByRole('article', {
        name: 'Article 2',
        exact: true,
      });
      const originalSecondTitle = await secondEditor
        .getByLabel('Article title')
        .inputValue();
      try {
        await editor
          .getByLabel('Article title')
          .fill('Updated through a scoped event');
        await editor.getByRole('button', { name: 'Save article' }).click();
        await expect(selected.locator('[data-refreshes]')).toHaveText('1');
        await expect(selected.locator('[data-article-title]')).toHaveText(
          'Updated through a scoped event',
        );
        await expect(selected.getByLabel('Local notes')).toHaveValue(
          'Keep selected notes',
        );
        await expect(other.getByLabel('Local notes')).toHaveValue(
          'Keep unrelated notes',
        );
        await expect(other.locator('[data-refreshes]')).toHaveText('0');
        expect(received).toEqual({ selected: 1, other: 0 });
        await secondEditor
          .getByLabel('Article title')
          .fill('Updated second article');
        await secondEditor
          .getByRole('button', { name: 'Save article' })
          .click();
        await expect(other.locator('[data-refreshes]')).toHaveText('1');
        await expect(other.locator('[data-article-title]')).toHaveText(
          'Updated second article',
        );
        await expect(selected.locator('[data-refreshes]')).toHaveText('1');
        expect(received).toEqual({ selected: 1, other: 1 });
      } finally {
        await restoreTitle(editor, originalTitle);
        await restoreTitle(secondEditor, originalSecondTitle);
      }
    });

    test('a preserved child replaces its listener after accepting a reactive prop', async ({
      page,
    }) => {
      await page.goto('/events');
      const selected = page.getByRole('region', {
        name: 'Selected article',
        exact: true,
      });
      const other = page.getByRole('region', {
        name: 'Article 2',
        exact: true,
      });
      const id = await islandId(selected);
      await selected.getByLabel('Local notes').fill('Keep this child draft');
      await page.getByLabel('Watched article').selectOption('2');
      await expect(selected.locator('[data-article-id]')).toHaveText('2');
      await expect(selected.locator('xpath=..')).toHaveAttribute(
        'data-interact-listeners',
        JSON.stringify({ 'article-saved:2': ['articleSaved'] }),
      );
      expect(await islandId(selected)).toBe(id);
      await expect(selected.getByLabel('Local notes')).toHaveValue(
        'Keep this child draft',
      );

      const requests: unknown[] = [];
      page.on('request', (request) => {
        if (targetsIsland(request, id)) requests.push(request.postDataJSON());
      });
      const editor = page.getByRole('article', {
        name: 'Article 1',
        exact: true,
      });
      const originalTitle = await editor
        .getByLabel('Article title')
        .inputValue();
      try {
        await editor
          .getByLabel('Article title')
          .fill('Unrelated save after switching');
        await editor.getByRole('button', { name: 'Save article' }).click();
        await expect(editor.getByRole('status')).toHaveText('Saved.');
        expect(requests).toHaveLength(0);
        await page.evaluate(() =>
          window.dispatchEvent(
            new CustomEvent('article-saved:2', { detail: {} }),
          ),
        );
        await expect(selected.locator('[data-refreshes]')).toHaveText('1');
        await expect(other.locator('[data-refreshes]')).toHaveText('1');
        expect(requests).toHaveLength(1);
        await expect(selected.getByLabel('Local notes')).toHaveValue(
          'Keep this child draft',
        );
      } finally {
        await restoreTitle(editor, originalTitle);
      }
    });

    test('a tampered concrete event name cannot invoke a differently scoped action', async ({
      page,
    }) => {
      await page.goto('/events');
      const selected = page.getByRole('region', {
        name: 'Selected article',
        exact: true,
      });
      const id = await islandId(selected);
      await page.route('**/_interact/update', async (route) => {
        if (!targetsIsland(route.request(), id)) return route.continue();
        const body: unknown = route.request().postDataJSON();
        if (typeof body !== 'object' || body === null || Array.isArray(body))
          throw new Error('Invalid request body.');
        await route.continue({
          postData: JSON.stringify({ ...body, event: 'article-saved:2' }),
        });
      });
      const rejected = page.waitForResponse((response) =>
        targetsIsland(response.request(), id),
      );
      await page.evaluate(() =>
        window.dispatchEvent(
          new CustomEvent('article-saved:1', { detail: {} }),
        ),
      );
      expect((await rejected).status()).toBe(400);
      await expect(selected.locator('[data-refreshes]')).toHaveText('0');
    });
  });
}
