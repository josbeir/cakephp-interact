import { expect, test } from './fixtures';
import { islandId, targetsIsland } from './requests';

for (const kind of ['HTML', 'FormHelper']) {
  test(`${kind} retains server-added rows and newer typing in the same array draft`, async ({
    page,
  }) => {
    await page.goto(kind === 'HTML' ? '/demo/form-fixtures' : '/forms');
    const region = page.getByRole('region', {
      name: `${kind} article`,
      exact: true,
    });
    const id = await islandId(region.locator('[data-interact-id]').first());
    const title = region.getByLabel('Title', { exact: true });
    await title.fill('Original');
    let release: () => void = () => {};
    const pending = new Promise<void>((resolve) => {
      release = resolve;
    });
    let received: () => void = () => {};
    const ready = new Promise<void>((resolve) => {
      received = resolve;
    });
    await page.route('**/_interact/update', async (route) => {
      if (!targetsIsland(route.request(), id)) {
        await route.continue();
        return;
      }
      const reply = await route.fetch();
      received();
      await pending;
      await route.fulfill({ response: reply });
    });
    await region
      .getByRole('button', { name: 'Add comment', exact: true })
      .click();
    await ready;
    await title.fill('Typed while adding');
    const response = page.waitForResponse((reply) =>
      targetsIsland(reply.request(), id),
    );
    release();
    await response;
    await expect(
      region.getByLabel('Comment 2 text', { exact: true }),
    ).toBeVisible();
    await expect(title).toHaveValue('Typed while adding');
    const draft = await title.evaluate((element) => {
      if (!window.Alpine) throw new Error('Missing Alpine runtime.');
      return window.Alpine.evaluate<string>(element, '$server.exportDraft()');
    });
    expect(JSON.parse(draft)).toMatchObject({
      data: {
        title: 'Typed while adding',
        comments: [
          { body: '', approved: false },
          { body: '', approved: false },
        ],
      },
    });
  });

  test(`${kind} native form binds nested drafts, validates, and saves with keyboard submission`, async ({
    page,
  }) => {
    const errors: string[] = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await page.goto(kind === 'HTML' ? '/demo/form-fixtures' : '/forms');
    const region = page.getByRole('region', {
      name: `${kind} article`,
      exact: true,
    });
    const id = await islandId(region.locator('[data-interact-id]').first());
    const title = region.getByLabel('Title', { exact: true });
    await title.fill('Nested article');
    await region
      .getByLabel('Body', { exact: true })
      .fill('<script>draft</script>');
    await region
      .getByLabel('Comment 1 text', { exact: true })
      .fill('First comment');
    await region.getByLabel('Published', { exact: true }).check();
    await region.getByLabel('CakePHP', { exact: true }).check();
    await region.getByLabel('Approve comment 1', { exact: true }).check();
    let response = page.waitForResponse((reply) =>
      targetsIsland(reply.request(), id),
    );
    await region
      .getByRole('button', { name: 'Add comment', exact: true })
      .click();
    expect((await response).ok()).toBe(true);
    await expect(
      region.getByLabel('Comment 1 text', { exact: true }),
    ).toHaveValue('First comment');
    await region
      .getByLabel('Comment 2 text', { exact: true })
      .fill('Second comment');
    response = page.waitForResponse((reply) =>
      targetsIsland(reply.request(), id),
    );
    await region
      .getByRole('button', { name: 'Remove comment 2', exact: true })
      .click();
    await response;
    await expect(
      region.getByLabel('Comment 2 text', { exact: true }),
    ).toHaveCount(0);
    await title.evaluate((input) => input.removeAttribute('maxlength'));
    await title.fill('x'.repeat(121));
    response = page.waitForResponse((reply) =>
      targetsIsland(reply.request(), id),
    );
    await region
      .getByRole('button', { name: 'Save article', exact: true })
      .click();
    expect((await response).status()).toBe(422);
    await expect(
      region.getByText('Use up to 120 characters.', { exact: true }),
    ).toBeVisible();
    await expect(region.getByLabel('Body', { exact: true })).toHaveValue(
      '<script>draft</script>',
    );
    await expect(region.getByLabel('Published', { exact: true })).toBeChecked();
    await expect(
      region.getByLabel('Approve comment 1', { exact: true }),
    ).toBeChecked();
    await title.fill('Saved nested article');
    response = page.waitForResponse((reply) =>
      targetsIsland(reply.request(), id),
    );
    await title.press('Enter');
    const accepted = await response;
    expect(accepted.ok()).toBe(true);
    expect(accepted.request().postDataJSON()).toMatchObject({
      action: 'save',
      updates: {
        data: {
          title: 'Saved nested article',
          body: '<script>draft</script>',
          published: true,
          topics: ['cakephp'],
          comments: [{ body: 'First comment', approved: true }],
        },
      },
    });
    await expect(region.getByRole('status')).toHaveText('Article saved.');
    await expect(title).toBeFocused();
    expect(errors).toEqual([]);
  });

  test(`${kind} form captures unblurred values once and preserves newer edits through a delayed response`, async ({
    page,
  }) => {
    await page.goto(kind === 'HTML' ? '/demo/form-fixtures' : '/forms');
    const region = page.getByRole('region', {
      name: `${kind} article`,
      exact: true,
    });
    const id = await islandId(region.locator('[data-interact-id]').first());
    const title = region.getByLabel('Title', { exact: true });
    await title.fill('First draft');
    await region
      .getByLabel('Comment 1 text', { exact: true })
      .fill('A comment');
    let release: () => void = () => {};
    const pending = new Promise<void>((resolve) => {
      release = resolve;
    });
    let requests = 0;
    await page.route('**/_interact/update', async (route) => {
      if (!targetsIsland(route.request(), id)) {
        await route.continue();
        return;
      }
      requests++;
      const reply = await route.fetch();
      await pending;
      await route.fulfill({ response: reply });
    });
    // Programmatic edits deliberately have no input or blur event before submission.
    await title.evaluate((element) => {
      if (element instanceof HTMLInputElement)
        element.value = 'Unblurred draft';
    });
    await region.locator('form').evaluate((form) => {
      if (form instanceof HTMLFormElement) {
        form.requestSubmit();
        form.requestSubmit();
      }
    });
    await expect(
      region.getByRole('button', { name: 'Save article', exact: true }),
    ).toBeDisabled();
    await title.fill('Newer draft');
    const response = page.waitForResponse((reply) =>
      targetsIsland(reply.request(), id),
    );
    release();
    const accepted = await response;
    expect(accepted.request().postDataJSON()).toMatchObject({
      updates: { data: { title: 'Unblurred draft' } },
    });
    await expect(title).toHaveValue('Newer draft');
    await expect(title).toBeFocused();
    await expect(
      region.getByRole('button', { name: 'Save article', exact: true }),
    ).toBeEnabled();
    expect(requests).toBe(1);
  });
}

test('native constraints prevent requests and child islands remain independently owned', async ({
  page,
}) => {
  await page.goto('/demo/form-fixtures');
  const region = page.getByRole('region', {
    name: 'HTML article',
    exact: true,
  });
  const parentId = await islandId(region.locator('[data-interact-id]').first());
  const child = region.getByRole('region', {
    name: 'Nested counter',
    exact: true,
  });
  const childId = await islandId(child);
  let parentRequests = 0;
  page.on('request', (request) => {
    if (targetsIsland(request, parentId)) parentRequests++;
  });
  await region
    .getByRole('button', { name: 'Save article', exact: true })
    .click();
  await expect(region.getByLabel('Title', { exact: true })).toBeFocused();
  expect(parentRequests).toBe(0);
  await child.evaluate((element) =>
    element.insertAdjacentHTML(
      'beforeend',
      '<label>Child draft<input name="title" value="child draft"></label>',
    ),
  );
  await region.getByLabel('Title', { exact: true }).fill('Parent draft');
  await region.getByLabel('Comment 1 text', { exact: true }).fill('Comment');
  const response = page.waitForResponse((reply) =>
    targetsIsland(reply.request(), parentId),
  );
  await region
    .getByRole('button', { name: 'Add comment', exact: true })
    .click();
  expect((await response).request().postDataJSON()).toMatchObject({
    updates: { data: { title: 'Parent draft' } },
  });
  await expect(child.getByLabel('Child draft')).toHaveValue('child draft');
  const childResponse = page.waitForResponse((reply) =>
    targetsIsland(reply.request(), childId),
  );
  await child.getByRole('button', { name: 'Add five', exact: true }).click();
  expect((await childResponse).ok()).toBe(true);
  await expect(child.locator('.counter-value')).toHaveText('Count: 5');
  await expect(region.getByLabel('Title', { exact: true })).toHaveValue(
    'Parent draft',
  );
  expect(parentRequests).toBe(1);
});

test('explicit overrides can be added and removed from an inferred control', async ({
  page,
}) => {
  const errors: string[] = [];
  page.on('pageerror', (error) => errors.push(error.message));
  await page.goto('/demo/form-fixtures');
  const region = page.getByRole('region', {
    name: 'HTML article',
    exact: true,
  });
  const id = await islandId(region.locator('[data-interact-id]').first());
  const title = region.getByLabel('Title', { exact: true });
  await title.fill('Original title');
  await title.evaluate((element) => {
    element.setAttribute('name', 'unparsed[][name]');
    element.setAttribute('x-interact:model', 'data.override');
  });
  await expect(title).toHaveValue('');
  await title.fill('Absolute draft');
  await title.evaluate((element) => {
    element.setAttribute('name', 'title');
    element.removeAttribute('x-interact:model');
  });
  await expect(title).toHaveValue('Original title');
  await region.getByLabel('Comment 1 text', { exact: true }).fill('Comment');
  const response = page.waitForResponse((reply) =>
    targetsIsland(reply.request(), id),
  );
  await region
    .getByRole('button', { name: 'Add comment', exact: true })
    .click();
  const accepted = await response;
  expect(accepted.ok()).toBe(true);
  expect(accepted.request().postDataJSON()).toMatchObject({
    updates: {
      data: {
        title: 'Original title',
        override: 'Absolute draft',
      },
    },
  });
  expect(errors).toEqual([]);
});

test('invalid dynamic names show feedback once and require a fresh mount', async ({
  page,
}) => {
  const errors: string[] = [];
  page.on('pageerror', (error) => errors.push(error.message));
  await page.goto('/demo/form-fixtures');
  const region = page.getByRole('region', {
    name: 'HTML article',
    exact: true,
  });
  const root = region.locator('[data-interact-id]').first();
  await root.evaluate((element) => {
    let failures = 0;
    element.addEventListener('interact:error', () => {
      element.setAttribute('data-feedback-count', String(++failures));
    });
  });
  const title = region.getByLabel('Title', { exact: true });
  await title.evaluate((element) =>
    element.setAttribute('name', 'comments[][body]'),
  );
  await expect(
    region.getByRole('alert').filter({ hasText: 'could not apply' }),
  ).toBeVisible();
  await expect(root).toHaveAttribute('data-feedback-count', '1');
  await page.reload();
  await title.fill('Fresh draft');
  await expect(title).toHaveValue('Fresh draft');
  await expect(
    region.getByRole('alert').filter({ hasText: 'could not apply' }),
  ).toHaveCount(0);
  expect(errors).toEqual([]);
});
