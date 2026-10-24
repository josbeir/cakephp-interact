import { test, expect } from './fixtures';

test('discovers every navigation modifier order outside x-data', async ({
  page,
}) => {
  const combinations: string[][] = [[]];
  const modifiers = ['trail', 'hover', 'preserve-scroll', 'no-prefetch'];
  for (const combination of combinations)
    for (const modifier of modifiers)
      if (!combination.includes(modifier))
        combinations.push([...combination, modifier]);
  const links = combinations
    .map((combination, index) => {
      const suffix = combination.map((modifier) => `.${modifier}`).join('');
      return `<a href="/navigation" x-interact:navigate${suffix}>Link ${index}</a>`;
    })
    .join('');
  await page.route('**/navigation/details', async (route) => {
    const response = await route.fetch();
    const body = (await response.text()).replace(
      '<body>',
      `<body><nav aria-label="Modifier discovery">${links}</nav>`,
    );
    await route.fulfill({ response, body });
  });
  await page.goto('/navigation/details');
  const menu = page.getByRole('navigation', { name: 'Modifier discovery' });
  await expect(menu.getByRole('link')).toHaveCount(65);
  for (const [index, combination] of combinations.entries()) {
    const link = menu.getByRole('link', { name: `Link ${index}`, exact: true });
    if (combination.includes('trail'))
      await expect(link).toHaveAttribute('data-interact-active-trail', '');
    else await expect(link).not.toHaveAttribute('data-interact-active-trail');
    await expect(link).not.toHaveAttribute('aria-current');
  }
});

test('tracks opted-in trails through persisted navigation and Back/Forward', async ({
  page,
}) => {
  const errors: string[] = [];
  page.on('pageerror', (error) => errors.push(error.message));
  await page.goto('/navigation/details');
  const menu = page.getByRole('navigation', { name: 'Demo pages' });
  const section = menu.getByRole('link', { name: 'Navigation', exact: true });
  const home = menu.getByRole('link', {
    name: 'Embedded islands',
    exact: true,
  });
  const tabs = page.getByRole('navigation', { name: 'Navigation example' });
  const overview = tabs.getByRole('link', { name: 'Overview', exact: true });
  const details = tabs.getByRole('link', { name: 'Details', exact: true });
  const retained = await section.elementHandle();
  if (!retained) throw new Error('Missing navigation section link.');
  await expect(section).toHaveAttribute('data-interact-active-trail', '');
  await expect(section).not.toHaveAttribute('aria-current');
  await expect(overview).not.toHaveAttribute('data-interact-active-trail');
  await expect(overview).not.toHaveAttribute('aria-current');
  await expect(details).toHaveAttribute('aria-current', 'page');
  await expect(home).not.toHaveAttribute('data-interact-active-trail');

  await overview.click();
  await expect(section).toHaveAttribute('aria-current', 'page');
  await expect(section).toHaveAttribute('data-interact-active-trail', '');
  await expect(overview).toHaveAttribute('aria-current', 'page');
  await expect(overview).not.toHaveAttribute('data-interact-active-trail');
  await details.click();
  await expect(details).toHaveAttribute('aria-current', 'page');
  await expect(section).toHaveAttribute('data-interact-active-trail', '');
  await expect(section).not.toHaveAttribute('aria-current');

  await home.click();
  await expect(home).toHaveAttribute('aria-current', 'page');
  await expect(section).not.toHaveAttribute('data-interact-active-trail');
  await page.goBack();
  await expect(details).toHaveAttribute('aria-current', 'page');
  await expect(section).toHaveAttribute('data-interact-active-trail', '');
  await expect(section).not.toHaveAttribute('aria-current');
  await page.goForward();
  await expect(home).toHaveAttribute('aria-current', 'page');
  await expect(section).not.toHaveAttribute('data-interact-active-trail');
  expect(await retained.evaluate((link) => link.isConnected)).toBe(true);
  expect(errors).toEqual([]);
});

test('visits ordinary CakePHP pages, retains layout islands, and mounts fresh page content', async ({
  page,
}) => {
  const errors: string[] = [];
  page.on('pageerror', (error) => errors.push(error.message));
  await page.goto('/navigation');
  await page.evaluate(() => {
    window.name = 'same navigation document';
  });
  const notebook = page.locator(
    '[x-interact\\:persist="notebook"] [data-interact-id]',
  );
  const identity = await notebook.getAttribute('data-interact-id');
  await page
    .getByLabel('Notebook draft', { exact: true })
    .fill('Keep this draft');
  await page
    .getByRole('button', { name: 'Remember draft', exact: true })
    .click();
  await expect(notebook.getByRole('status')).toContainText('1');
  await page
    .getByLabel('Notebook draft', { exact: true })
    .fill('Newer unsynchronized draft');
  await page
    .getByRole('navigation', { name: 'Navigation example' })
    .getByRole('link', { name: 'Details', exact: true })
    .click();
  await expect(
    page.getByRole('heading', { name: 'Details', exact: true }),
  ).toBeVisible();
  await expect(notebook).toHaveAttribute(
    'data-interact-id',
    identity ?? 'missing',
  );
  await expect(page.getByLabel('Notebook draft', { exact: true })).toHaveValue(
    'Newer unsynchronized draft',
  );
  await expect(notebook.getByRole('status')).toContainText('1');
  await expect(page).toHaveTitle('Details · Navigation · Interact');
  await expect(page.locator('meta[name="description"]')).toHaveAttribute(
    'content',
    'Details navigation example',
  );
  await expect(
    page
      .getByRole('navigation', { name: 'Navigation example' })
      .getByRole('link', { name: 'Details', exact: true }),
  ).toHaveAttribute('aria-current', 'page');
  await page
    .getByRole('button', { name: 'Remember draft', exact: true })
    .click();
  await expect(notebook.getByRole('status')).toContainText('2');
  expect(await page.evaluate(() => window.name)).toBe(
    'same navigation document',
  );
  expect(errors).toEqual([]);
});

test('shows delayed application feedback and cancellation leaves the page usable', async ({
  page,
}) => {
  await page.goto('/navigation');
  let release: () => void = () => {};
  const gate = new Promise<void>((resolve) => {
    release = resolve;
  });
  await page.route('**/navigation/details', async (route) => {
    await gate;
    await route.continue();
  });
  await page
    .getByRole('navigation', { name: 'Navigation example' })
    .getByRole('link', { name: 'Details', exact: true })
    .click();
  await expect(
    page.getByRole('status', { name: 'Loading page', exact: true }),
  ).toBeVisible();
  release();
  await expect(
    page.getByRole('heading', { name: 'Details', exact: true }),
  ).toBeVisible();
  await expect(
    page.getByRole('status', { name: 'Loading page', exact: true }),
  ).toBeHidden();
  await page.evaluate(() =>
    document.addEventListener(
      'interact:navigate',
      (event) => event.preventDefault(),
      { once: true },
    ),
  );
  await page
    .getByRole('navigation', { name: 'Navigation example' })
    .getByRole('link', { name: 'Overview', exact: true })
    .click();
  await expect(
    page.getByRole('heading', { name: 'Details', exact: true }),
  ).toBeVisible();
  await page
    .getByRole('button', { name: 'Remember draft', exact: true })
    .click();
  await expect(page.locator('[x-interact\\:persist="notebook"]')).toContainText(
    'Remembered 1 times',
  );
});

test('loads new page assets without restarting Alpine', async ({ page }) => {
  const errors: string[] = [];
  page.on('pageerror', (error) => errors.push(error.message));
  await page.goto('/navigation');
  await page.evaluate(() => {
    document.documentElement.dataset.navigationDocument = 'original';
  });
  await page
    .getByRole('navigation', { name: 'Demo pages' })
    .getByRole('link', { name: 'Playground', exact: true })
    .click();
  await expect(page.getByRole('heading', { name: 'Playground' })).toBeVisible();
  await expect(page.locator('.ts-wrapper')).toBeVisible();
  await expect(page.locator('head script[src$="/js/widgets.js"]')).toHaveCount(
    1,
  );
  expect(errors).toEqual([]);
});

test('honors enhanced action redirects and changed mount props', async ({
  page,
}) => {
  await page.goto('/navigation');
  const notebook = page.locator(
    '[x-interact\\:persist="notebook"] [data-interact-id]',
  );
  const identity = await notebook.getAttribute('data-interact-id');
  await page
    .getByRole('button', { name: 'Open details through an action' })
    .click();
  await expect(
    page.getByRole('heading', { name: 'Details', exact: true }),
  ).toBeVisible();
  await expect(notebook).toHaveAttribute(
    'data-interact-id',
    identity ?? 'missing',
  );
  await page
    .getByRole('button', { name: 'Remember draft', exact: true })
    .click();
  await expect(notebook.getByRole('status')).toContainText('1');
  await page.getByRole('link', { name: 'Change notebook props' }).click();
  await expect(notebook).not.toHaveAttribute(
    'data-interact-id',
    identity ?? 'missing',
  );
  await expect(notebook).toContainText('Edition: new');
});

test('distinguishes query history from fresh cross-page history without replaying actions', async ({
  page,
}) => {
  const errors: string[] = [];
  page.on('pageerror', (error) => errors.push(error.message));
  await page.goto('/navigation');
  await page.getByLabel('Notebook URL filter').fill('alpha');
  await page.getByLabel('Notebook URL filter').press('Tab');
  await expect(page).toHaveURL('/navigation?notebook=alpha');
  await page
    .getByLabel('Notebook draft', { exact: true })
    .fill('Retained history draft');
  const counter = page.getByRole('region', { name: 'Page counter' });
  await counter.getByRole('button', { name: 'Add five' }).click();
  await expect(counter.locator('.counter-value')).toContainText('5');
  await page
    .getByRole('navigation', { name: 'Navigation example' })
    .getByRole('link', { name: 'Details', exact: true })
    .click();
  await expect(
    page.getByRole('heading', { name: 'Details', exact: true }),
  ).toBeVisible();
  await expect(page.getByLabel('Notebook URL filter')).toHaveValue('');
  await expect(page.getByLabel('Notebook draft', { exact: true })).toHaveValue(
    'Retained history draft',
  );
  await page.goBack();
  await expect(
    page.getByRole('heading', { name: 'Overview', exact: true }),
  ).toBeVisible();
  await expect(page.getByLabel('Notebook URL filter')).toHaveValue('alpha');
  await expect(counter.locator('.counter-value')).toContainText('0');
  await page.goBack();
  await expect(page.getByLabel('Notebook URL filter')).toHaveValue('');
  await expect(page.getByLabel('Notebook draft', { exact: true })).toHaveValue(
    'Retained history draft',
  );
  await page.goForward();
  await expect(page.getByLabel('Notebook URL filter')).toHaveValue('alpha');
  expect(errors).toEqual([]);
});

test('restores page and persistent container scroll and preserves focused draft selection', async ({
  page,
}) => {
  await page.goto('/navigation');
  const sidebar = page.getByLabel('Scrollable notebook references', {
    exact: true,
  });
  const draft = page.getByLabel('Notebook draft', { exact: true });
  await draft.fill('Focused retained draft');
  await draft.focus();
  await draft.evaluate((input) => {
    if (input instanceof HTMLInputElement) input.setSelectionRange(2, 8);
  });
  await sidebar.evaluate((node) => {
    node.scrollTop = 120;
  });
  await page.evaluate(() => window.scrollTo(0, 300));
  await page
    .getByRole('link', { name: 'Keep page scroll' })
    .evaluate((link) => {
      if (link instanceof HTMLElement) link.click();
    });
  await expect(
    page.getByRole('heading', { name: 'Details', exact: true }),
  ).toBeVisible();
  await expect(draft).toBeFocused();
  expect(
    await draft.evaluate((input) =>
      input instanceof HTMLInputElement
        ? [input.selectionStart, input.selectionEnd]
        : [],
    ),
  ).toEqual([2, 8]);
  await expect.poll(() => sidebar.evaluate((node) => node.scrollTop)).toBe(120);
  await expect.poll(() => page.evaluate(() => window.scrollY)).toBe(300);
  await page
    .getByRole('navigation', { name: 'Navigation example' })
    .getByRole('link', { name: 'Overview', exact: true })
    .evaluate((link) => {
      if (link instanceof HTMLElement) link.click();
    });
  await expect(
    page.getByRole('heading', { name: 'Overview', exact: true }),
  ).toBeVisible();
  await expect.poll(() => page.evaluate(() => window.scrollY)).toBe(300);
  await page.goBack();
  await expect(
    page.getByRole('heading', { name: 'Details', exact: true }),
  ).toBeVisible();
  await expect.poll(() => page.evaluate(() => window.scrollY)).toBe(300);
});

test('latest visit wins and an abandoned response cannot change the destination', async ({
  page,
}) => {
  await page.goto('/navigation');
  let release: () => void = () => {};
  const gate = new Promise<void>((resolve) => {
    release = resolve;
  });
  let started: () => void = () => {};
  const ready = new Promise<void>((resolve) => {
    started = resolve;
  });
  await page.route('**/navigation/details', async (route) => {
    started();
    await gate;
    await route.continue().catch(() => {});
  });
  const links = page.getByRole('navigation', { name: 'Navigation example' });
  await links.getByRole('link', { name: 'Details', exact: true }).click();
  await ready;
  await page.getByRole('link', { name: 'Change notebook props' }).click();
  await expect(page).toHaveURL('/navigation/details?edition=new');
  await expect(page.locator('[x-interact\\:persist="notebook"]')).toContainText(
    'Edition: new',
  );
  release();
  await expect(page).toHaveURL('/navigation/details?edition=new');
  await expect(page.getByRole('status', { name: 'Loading page' })).toBeHidden();
});

test('supports keyboard visits and keeps native same-page fragments', async ({
  page,
}) => {
  await page.goto('/navigation');
  const details = page
    .getByRole('navigation', { name: 'Navigation example' })
    .getByRole('link', { name: 'Details', exact: true });
  await details.focus();
  await details.press('Enter');
  await expect(
    page.getByRole('heading', { name: 'Details', exact: true }),
  ).toBeVisible();
  await page.getByRole('link', { name: 'Jump to destination' }).click();
  await expect(page).toHaveURL('/navigation/details#destination');
  await expect(
    page.getByRole('heading', { name: 'Destination section' }),
  ).toBeInViewport();
});

test('canceled action navigation and canceled history visits remain usable', async ({
  page,
}) => {
  await page.goto('/navigation');
  await page.evaluate(() =>
    document.addEventListener(
      'interact:navigate',
      (event) => event.preventDefault(),
      { once: true },
    ),
  );
  await page
    .getByRole('button', { name: 'Open details through an action' })
    .click();
  await expect(page).toHaveURL('/navigation');
  await page
    .getByRole('button', { name: 'Remember draft', exact: true })
    .click();
  await expect(page.locator('[x-interact\\:persist="notebook"]')).toContainText(
    'Remembered 1 times',
  );
  await page
    .getByRole('navigation', { name: 'Navigation example' })
    .getByRole('link', { name: 'Details', exact: true })
    .click();
  await expect(
    page.getByRole('heading', { name: 'Details', exact: true }),
  ).toBeVisible();
  await page.evaluate(() =>
    document.addEventListener(
      'interact:navigate',
      (event) => event.preventDefault(),
      { once: true },
    ),
  );
  await page.goBack();
  await expect(page).toHaveURL('/navigation/details');
  await expect(
    page.getByRole('heading', { name: 'Details', exact: true }),
  ).toBeVisible();
});

test('falls back to a native GET for incompatible document policy and never repeats an action', async ({
  page,
}) => {
  await page.goto('/navigation');
  await page.evaluate(() => {
    window.Alpine?.store('navigationProof', { original: true });
  });
  let requests = 0;
  await page.route('**/navigation/details', async (route) => {
    requests++;
    if (requests === 1) {
      const response = await route.fetch();
      const body = (await response.text()).replace(
        /(&quot;policy&quot;:&quot;)[a-f0-9]+/,
        '$1different',
      );
      await route.fulfill({ response, body });
    } else await route.continue();
  });
  let actions = 0;
  page.on('request', (request) => {
    if (
      request.method() === 'POST' &&
      request.url().includes('/_interact/update')
    )
      actions++;
  });
  await page
    .getByRole('button', { name: 'Open details through an action' })
    .click();
  await expect(
    page.getByRole('heading', { name: 'Details', exact: true }),
  ).toBeVisible();
  expect(requests).toBe(2);
  expect(actions).toBe(1);
  expect(
    await page.evaluate(() => window.Alpine?.store('navigationProof')),
  ).toBeUndefined();
});

test('retains manual loading ownership and initializes it only once after a visit', async ({
  page,
}) => {
  await page.goto('/navigation?manual=1');
  const notebook = page.locator(
    '[x-interact\\:persist="notebook"] [data-interact-id]',
  );
  const identity = await notebook.getAttribute('data-interact-id');
  await page
    .getByRole('navigation', { name: 'Navigation example' })
    .getByRole('link', { name: 'Details', exact: true })
    .click();
  await expect(
    page.getByRole('heading', { name: 'Details', exact: true }),
  ).toBeVisible();
  await expect(notebook).toHaveAttribute(
    'data-interact-id',
    identity ?? 'missing',
  );
  await expect(notebook).toHaveAttribute('data-interact-loading', 'manual');
  await notebook.evaluate((root) => {
    if (root instanceof HTMLElement)
      window.Alpine?.evaluateRaw(root, '$server.sync()');
  });
  await expect(
    page.getByLabel('Notebook draft', { exact: true }),
  ).toBeVisible();
  await page
    .getByRole('button', { name: 'Remember draft', exact: true })
    .click();
  await expect(notebook.getByRole('status')).toContainText('1');
});

test('reconciles a late retained-island response without losing newer drafts or redirecting a newer page', async ({
  page,
}) => {
  await page.goto('/navigation');
  let release: () => void = () => {};
  const gate = new Promise<void>((resolve) => {
    release = resolve;
  });
  let started: () => void = () => {};
  const ready = new Promise<void>((resolve) => {
    started = resolve;
  });
  await page.route('**/_interact/update', async (route) => {
    if (route.request().postData()?.includes('"action":"remember"')) {
      const response = await route.fetch();
      started();
      await gate;
      await route.fulfill({ response });
    } else await route.continue();
  });
  await page
    .getByLabel('Notebook draft', { exact: true })
    .fill('Submitted draft');
  await page
    .getByRole('button', { name: 'Remember draft', exact: true })
    .click();
  await ready;
  await page.getByLabel('Notebook draft', { exact: true }).fill('Newer draft');
  await page
    .getByRole('navigation', { name: 'Navigation example' })
    .getByRole('link', { name: 'Details', exact: true })
    .click();
  await expect(
    page.getByRole('heading', { name: 'Details', exact: true }),
  ).toBeVisible();
  release();
  await expect(page.locator('[x-interact\\:persist="notebook"]')).toContainText(
    'Remembered 1 times',
  );
  await expect(page.getByLabel('Notebook draft', { exact: true })).toHaveValue(
    'Newer draft',
  );
  await expect(page).toHaveURL('/navigation/details');
  await page.unroute('**/_interact/update');
  let releaseRedirect: () => void = () => {};
  const redirectGate = new Promise<void>((resolve) => {
    releaseRedirect = resolve;
  });
  let redirectStarted: () => void = () => {};
  const redirectReady = new Promise<void>((resolve) => {
    redirectStarted = resolve;
  });
  await page.route('**/_interact/update', async (route) => {
    if (route.request().postData()?.includes('"action":"openDetails"')) {
      const response = await route.fetch();
      redirectStarted();
      await redirectGate;
      await route.fulfill({ response });
    } else await route.continue();
  });
  await page
    .getByRole('button', { name: 'Open details through an action' })
    .click();
  await redirectReady;
  await page
    .getByRole('navigation', { name: 'Navigation example' })
    .getByRole('link', { name: 'Overview', exact: true })
    .click();
  await expect(
    page.getByRole('heading', { name: 'Overview', exact: true }),
  ).toBeVisible();
  releaseRedirect();
  await expect(
    page.getByRole('button', { name: 'Remember draft', exact: true }),
  ).toBeEnabled();
  await expect(page).toHaveURL('/navigation');
});

test('keeps exactly one active page tab and lays out the demo cards on mobile', async ({
  page,
}) => {
  await page.goto('/navigation');
  const tabs = page.getByRole('navigation', { name: 'Navigation example' });
  await expect(tabs.locator('[aria-current="page"]')).toHaveCount(1);
  await tabs.getByRole('link', { name: 'Details', exact: true }).click();
  await expect(
    page.getByRole('heading', { name: 'Details', exact: true }),
  ).toBeVisible();
  await expect(tabs.locator('[aria-current="page"]')).toHaveCount(1);
  await expect(
    tabs.getByRole('link', { name: 'Details', exact: true }),
  ).toHaveAttribute('aria-current', 'page');
  await page.setViewportSize({ width: 390, height: 844 });
  await expect(
    page.getByRole('heading', { name: 'Layout notebook', exact: true }),
  ).toBeVisible();
  expect(
    await page.evaluate(() => document.documentElement.scrollWidth),
  ).toBeLessThanOrEqual(390);
});

test('retains staged files and one polling lifecycle until the region is left', async ({
  page,
}) => {
  await page.goto('/navigation');
  await page
    .getByText('More lifecycle examples: uploads and polling', { exact: true })
    .click();
  const uploads = page.getByRole('region', { name: 'Document uploads' });
  await uploads.getByLabel('Main document', { exact: true }).setInputFiles({
    name: 'persistent.txt',
    mimeType: 'text/plain',
    buffer: Buffer.from('Retained upload'),
  });
  await expect(uploads.getByLabel('Main document files')).toContainText(
    'persistent.txt',
  );
  const job = page.getByRole('region', { name: 'Simulated job progress' });
  const jobRoot = job.locator('xpath=..');
  const jobId = await jobRoot.getAttribute('data-interact-id');
  let polls = 0;
  page.on('request', (request) => {
    if (
      request.method() === 'POST' &&
      request.postData()?.includes('"action":"refreshStatus"')
    )
      polls++;
  });
  await page
    .getByRole('navigation', { name: 'Navigation example' })
    .getByRole('link', { name: 'Details', exact: true })
    .click();
  await expect(
    page.getByRole('heading', { name: 'Details', exact: true }),
  ).toBeVisible();
  await expect(uploads.getByLabel('Main document files')).toContainText(
    'persistent.txt',
  );
  await expect(jobRoot).toHaveAttribute('data-interact-id', jobId ?? 'missing');
  await expect(job.getByRole('progressbar')).toHaveAttribute('value', '25', {
    timeout: 8000,
  });
  expect(polls).toBe(1);
  await uploads
    .getByLabel('Document title', { exact: true })
    .fill('Retained file');
  await uploads
    .getByRole('button', { name: 'Save documents', exact: true })
    .click();
  await expect(uploads).toContainText('Saved 1 file(s)');
  await page
    .getByRole('navigation', { name: 'Demo pages' })
    .getByRole('link', { name: 'Playground', exact: true })
    .click();
  await expect(page.getByRole('heading', { name: 'Playground' })).toBeVisible();
  await expect(page.locator(`[data-interact-id="${jobId}"]`)).toHaveCount(0);
});

test('native fragment entries remain distinct when a multi-entry Back is canceled', async ({
  page,
}) => {
  await page.goto('/navigation/details');
  await page.getByRole('link', { name: 'Jump to destination' }).click();
  await expect(page).toHaveURL('/navigation/details#destination');
  await page
    .getByRole('navigation', { name: 'Navigation example' })
    .getByRole('link', { name: 'Overview', exact: true })
    .click();
  await expect(
    page.getByRole('heading', { name: 'Overview', exact: true }),
  ).toBeVisible();
  await page.evaluate(() => {
    document.addEventListener(
      'interact:navigate',
      (event) => event.preventDefault(),
      { once: true },
    );
    window.history.go(-2);
  });
  await expect(page).toHaveURL('/navigation');
  await expect(
    page.getByRole('heading', { name: 'Overview', exact: true }),
  ).toBeVisible();
});

test('replaces inline head styles when returning to a previously visited page', async ({
  page,
}) => {
  await page.route(/\/navigation(?:\/details)?$/, async (route) => {
    const response = await route.fetch();
    const color = new URL(route.request().url()).pathname.endsWith('/details')
      ? 'blue'
      : 'red';
    const body = (await response.text()).replace(
      '</head>',
      `<style id="navigation-page-style">.navigation-page { outline-color: ${color}; }</style></head>`,
    );
    await route.fulfill({ response, body });
  });
  await page.goto('/navigation');
  const content = page.locator('.navigation-page');
  const tabs = page.getByRole('navigation', { name: 'Navigation example' });
  await expect(content).toHaveCSS('outline-color', 'rgb(255, 0, 0)');
  await tabs.getByRole('link', { name: 'Details', exact: true }).click();
  await expect(content).toHaveCSS('outline-color', 'rgb(0, 0, 255)');
  await tabs.getByRole('link', { name: 'Overview', exact: true }).click();
  await expect(content).toHaveCSS('outline-color', 'rgb(255, 0, 0)');
  await expect(page.locator('head #navigation-page-style')).toHaveCount(1);
});
