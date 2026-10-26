import { expect, test } from './fixtures';
import { islandId, targetsIsland } from './requests';

test('a named save event invokes the parent action once and retains another child draft', async ({
  page,
}) => {
  await page.goto('/');
  const listing = page.getByRole('region', { name: 'Articles', exact: true });
  const id = await islandId(listing);
  const requests: unknown[] = [];
  page.on('request', (request) => {
    if (targetsIsland(request, id)) requests.push(request.postDataJSON());
  });
  const first = page.getByRole('article', { name: 'Article 1', exact: true });
  const other = page.getByRole('article', { name: 'Article 2', exact: true });
  await other.getByLabel('Article title').fill('Keep this unrelated draft');
  await listing.getByRole('button', { name: 'Toggle help' }).click();

  const originalTitle = await first.getByLabel('Article title').inputValue();
  try {
    for (const title of ['First event save', 'Second event save']) {
      await first.getByLabel('Article title').fill(title);
      const refreshed = page.waitForResponse((response) =>
        targetsIsland(response.request(), id),
      );
      await first.getByRole('button', { name: 'Save article' }).click();
      const response = await refreshed;
      expect(response.status()).toBe(200);
      const payload: unknown = await response.json();
      if (
        typeof payload !== 'object' ||
        payload === null ||
        !('snapshot' in payload) ||
        typeof payload.snapshot !== 'string'
      )
        throw new Error('Missing response snapshot.');
      await expect(listing.locator('xpath=..')).toHaveAttribute(
        'data-interact-snapshot',
        payload.snapshot,
      );
      await expect(other.getByLabel('Article title')).toHaveValue(
        'Keep this unrelated draft',
      );
      await expect(
        listing.getByText('Search and edit without leaving the page.'),
      ).toBeVisible();
    }

    expect(requests).toEqual([
      expect.objectContaining({
        action: 'articleSaved',
        event: 'article-saved',
        args: [{ id: 1 }],
      }),
      expect.objectContaining({
        action: 'articleSaved',
        event: 'article-saved',
        args: [{ id: 1 }],
      }),
    ]);
  } finally {
    const editorId = await islandId(first);
    const restored = page.waitForResponse((response) =>
      targetsIsland(response.request(), editorId),
    );
    await first.getByLabel('Article title').fill(originalTitle);
    await first.getByRole('button', { name: 'Save article' }).click();
    await restored;
  }
});

test('browser events outside the listing invoke its declared action', async ({
  page,
}) => {
  await page.goto('/');
  const listing = page.getByRole('region', { name: 'Articles', exact: true });
  const id = await islandId(listing);
  const refreshed = page.waitForResponse((response) =>
    targetsIsland(response.request(), id),
  );
  await page
    .getByRole('region', { name: 'Dependent choices', exact: true })
    .evaluate((element) => {
      element.dispatchEvent(
        new CustomEvent('article-saved', {
          detail: { id: 2, title: 'Browser event' },
          bubbles: true,
        }),
      );
    });
  const response = await refreshed;
  expect(response.status()).toBe(200);
  expect(response.request().postDataJSON()).toMatchObject({
    action: 'articleSaved',
    event: 'article-saved',
    args: [{ id: 2, title: 'Browser event' }],
  });
});
