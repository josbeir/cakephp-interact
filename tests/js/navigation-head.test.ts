import { afterEach, beforeEach, expect, it, vi } from 'vite-plus/test';
import { PageAssets } from '../../resources/ts/navigation/assets';
import { assetPolicy } from '../../resources/ts/navigation/head';
import {
  incomingDocument,
  documentIdentity,
} from '../../resources/ts/navigation/document';
import { responseNonces } from '../../resources/ts/navigation/nonces';

function parsed(head: string): Document {
  return new DOMParser().parseFromString(
    `<!doctype html><html><head>${head}</head><body></body></html>`,
    'text/html',
  );
}
function autoload(): void {
  const original = document.head.append.bind(document.head);
  vi.spyOn(document.head, 'append').mockImplementation((...nodes) => {
    original(...nodes);
    for (const node of nodes)
      if (node instanceof Element)
        queueMicrotask(() => node.dispatchEvent(new Event('load')));
  });
}
async function commit(assets: PageAssets, head: string): Promise<void> {
  (
    await assets.prepare(
      parsed(head),
      window.location.href,
      new AbortController().signal,
    )
  )();
}
beforeEach(() => {
  document.head.replaceChildren();
});
afterEach(() => {
  document.head.replaceChildren();
  vi.restoreAllMocks();
});

it('reuses equivalent nodes one-to-one, keeps cascade order, and preserves runtime-owned head elements', async () => {
  document.head.innerHTML =
    '<meta name="tag" content="one"><meta name="tag" content="one"><link href="/common.css" rel="stylesheet"><style>.page{color:red}</style>';
  const initial = [...document.head.children];
  const assets = new PageAssets(document);
  const runtime = document.createElement('style');
  runtime.textContent = '.runtime{color:green}';
  document.head.append(runtime);
  autoload();
  await commit(
    assets,
    '<style>.page{color:blue}</style><link rel="stylesheet" href="/common.css"><meta content="one" name="tag"><meta content="one" name="tag"><link rel="stylesheet" href="/page.css"><style>.page{color:purple}</style>',
  );
  expect([...document.head.children].map((node) => node.localName)).toEqual([
    'style',
    'link',
    'meta',
    'meta',
    'link',
    'style',
    'style',
  ]);
  expect(document.head.children[1]).toBe(initial[2]);
  expect(document.head.children[2]).toBe(initial[0]);
  expect(document.head.children[3]).toBe(initial[1]);
  expect(document.head.lastElementChild).toBe(runtime);
  expect(initial[3]?.isConnected).toBe(false);
  const common = document.head.children[1];
  await commit(assets, '<link rel="stylesheet" href="/common.css">');
  expect([...document.head.children]).toEqual([common, runtime]);
});

it('keeps omitted persistent styles before destination styles and accepts authoritative updates by id', async () => {
  document.head.innerHTML =
    '<style id="shared" x-interact:asset="persist">.panel{color:red}</style><style>.old{}</style>';
  const retained = document.getElementById('shared');
  const assets = new PageAssets(document);
  await commit(assets, '<style>.destination{color:blue}</style>');
  expect(document.head.firstElementChild).toBe(retained);
  await commit(
    assets,
    '<style>.destination{}</style><style id="shared" x-interact:asset="persist">.panel{color:green}</style>',
  );
  expect(document.head.lastElementChild?.id).toBe('shared');
  expect(document.getElementById('shared')?.textContent).toContain('green');
  await commit(assets, '<style id="shared">.panel{color:purple}</style>');
  await commit(assets, '<title>Next</title>');
  expect(document.getElementById('shared')).toBeNull();
});

it.each([
  '<style x-interact:asset="reload">.page{}</style>',
  '<meta x-interact:asset="reload">',
  '<script x-interact:asset="persist" id="wrong" src="/app.js"></script>',
  '<link rel="stylesheet" x-interact:asset="persist" href="/page.css">',
  '<style id="duplicate" x-interact:asset="persist"></style><meta id="duplicate">',
  '<link rel="stylesheet" x-interact:asset.foo="reload" href="/page.css">',
  '<link rel="stylesheet" x-interact:asset="arbitrary" href="/page.css">',
])(
  'rejects unsupported or ambiguous head policies before any mutations: %s',
  async (head) => {
    document.head.innerHTML = '<title>Original</title>';
    const original = document.head.innerHTML;
    await expect(commit(new PageAssets(document), head)).rejects.toThrow();
    expect(document.head.innerHTML).toBe(original);
  },
);

it.each([
  '<script src="/app.v2.js" x-interact:asset="reload"></script>',
  '<script src="/app.v1.js?v=2" x-interact:asset="reload"></script>',
  '<script src="/app.v1.js" x-interact:asset="reload" integrity="sha256-change"></script>',
  '',
  '<script src="/app.v1.js" x-interact:asset="reload"></script><link rel="stylesheet" href="/new.css" x-interact:asset="reload">',
])(
  'falls back before loading anything when tracked assets change: %s',
  async (head) => {
    document.head.innerHTML =
      '<script src="/app.v1.js" x-interact:asset="reload"></script>';
    const append = vi.spyOn(document.head, 'append');
    await expect(commit(new PageAssets(document), head)).rejects.toThrow(
      'Tracked',
    );
    expect(append).not.toHaveBeenCalled();
  },
);

it('tracks order and stylesheet attributes, supports custom prefixes, and keeps disabled/alternate styles inactive', async () => {
  const first =
    '<link rel="stylesheet" href="/one.css" ui-interact:asset="reload">';
  const second = '<script src="/two.js" ui-interact:asset="reload"></script>';
  document.head.innerHTML = first + second;
  const assets = new PageAssets(document, 'ui-interact:asset');
  await commit(assets, first + second);
  await expect(commit(assets, second + first)).rejects.toThrow('Tracked');
  await expect(
    commit(
      assets,
      first.replace('rel="stylesheet"', 'rel="stylesheet" media="print"') +
        second,
    ),
  ).rejects.toThrow('Tracked');
  await commit(
    assets,
    first +
      second +
      '<link rel="stylesheet" disabled href="/disabled.css"><link rel="alternate stylesheet" title="Alternative" href="/alternate.css">',
  );
  expect(
    document.querySelector('link[disabled]')?.hasAttribute('disabled'),
  ).toBe(true);
  expect(
    document
      .querySelector('link[rel="alternate stylesheet"]')
      ?.getAttribute('title'),
  ).toBe('Alternative');
  const bodyStyle = document.createElement('style');
  bodyStyle.setAttribute('x-interact:asset', 'persist');
  bodyStyle.id = 'body';
  document.body.append(bodyStyle);
  expect(() => assetPolicy(bodyStyle, 'x-interact:asset')).toThrow();
  bodyStyle.remove();
});

it('retains the current head after preparation fails or a prepared visit is superseded', async () => {
  document.head.innerHTML =
    '<link rel="stylesheet" href="/current.css"><style>.current{}</style>';
  const original = [...document.head.children];
  const assets = new PageAssets(document);
  const pending = assets.prepare(
    parsed('<link rel="stylesheet" href="/broken.css">'),
    window.location.href,
    new AbortController().signal,
  );
  const rejected = expect(pending).rejects.toThrow('failed');
  document.querySelector('link[media]')?.dispatchEvent(new Event('error'));
  await rejected;
  expect([...document.head.children]).toEqual(original);
  autoload();
  const controller = new AbortController();
  const stale = await assets.prepare(
    parsed('<link rel="stylesheet" href="/stale.css">'),
    window.location.href,
    controller.signal,
  );
  controller.abort();
  expect(() => stale()).toThrow();
  expect([...document.head.children]).toEqual(original);
});

it('reconciles a large duplicate-heavy head without replacing or mutating unchanged nodes', async () => {
  const markup = Array.from(
    { length: 1000 },
    (_, index) => `<meta name="entry-${index % 10}" content="shared">`,
  ).join('');
  document.head.innerHTML = markup;
  const nodes = [...document.head.children];
  const assets = new PageAssets(document);
  const insert = vi.spyOn(document.head, 'insertBefore');
  const remove = vi.spyOn(Element.prototype, 'remove');
  await commit(assets, markup);
  expect([...document.head.children]).toEqual(nodes);
  expect(insert).not.toHaveBeenCalled();
  expect(remove).not.toHaveBeenCalled();
});

it('preserves relative URL meanings across route changes and never reruns a removed script', async () => {
  window.history.replaceState({}, '', '/first/page');
  document.head.innerHTML =
    '<link rel="canonical" href="canonical"><link id="shared" rel="stylesheet" href="shared.css" x-interact:asset="persist">';
  const assets = new PageAssets(document);
  const canonical = document.querySelector('link[rel="canonical"]');
  const shared = document.getElementById('shared');
  autoload();
  const append = vi.spyOn(document.head, 'append');
  const prepared = await assets.prepare(
    parsed(
      '<link rel="canonical" href="../first/canonical"><script src="/once.js"></script>',
    ),
    new URL('/second/page', window.location.href).href,
    new AbortController().signal,
  );
  window.history.pushState({}, '', '/second/page');
  prepared();
  expect(document.querySelector('link[rel="canonical"]')).toBe(canonical);
  expect(canonical?.getAttribute('href')).toBe(
    new URL('/first/canonical', window.location.href).href,
  );
  expect(shared?.getAttribute('href')).toBe(
    new URL('/first/shared.css', window.location.href).href,
  );
  await commit(assets, '<title>No script</title>');
  await commit(assets, '<script src="/once.js"></script>');
  expect(append).toHaveBeenCalledTimes(1);
  expect(document.querySelectorAll('script[src]')).toHaveLength(1);
});

const identity = {
  version: '1',
  application: '',
  session: 'session',
  policy: 'policy',
  nonceCount: 2,
};
function nonceHead(script: string, style: string, extra = ''): string {
  return `<meta name="interact-navigation" content='${JSON.stringify(identity)}'><meta name="interact-navigation-nonce" content="0" nonce="${script}"><meta name="interact-navigation-nonce" content="1" nonce="${style}">${extra}`;
}
it('maps only response-advertised nonces, preserves separate capabilities, and scrubs pre-stamped active values', () => {
  document.head.innerHTML = nonceHead('activeScript', 'activeStyle');
  const headers = new Headers({
    'Content-Security-Policy':
      "script-src 'nonce-newScript'; style-src 'nonce-newStyle'; report-uri /nonce-irrelevant",
    'Content-Security-Policy-Report-Only': "default-src 'nonce-newScript'",
  });
  expect(responseNonces(headers)).toEqual(['newScript', 'newStyle']);
  const next = incomingDocument(
    parsed(
      nonceHead(
        'newScript',
        'newStyle',
        '<style nonce="newStyle">.safe{}</style><script src="/asset.js" nonce="newScript"></script><style id="unknown" nonce="unknown">.untrusted{}</style><style id="stamped" nonce="activeStyle">.untrusted{}</style>',
      ),
    ).documentElement.outerHTML.replace('<html>', '<!doctype html><html>'),
    document,
    responseNonces(headers),
  );
  expect(next.querySelector('style')?.nonce).toBe('activeStyle');
  expect(next.querySelector('script')?.nonce).toBe('activeScript');
  expect(next.getElementById('unknown')?.getAttribute('nonce')).toBe('unknown');
  expect(next.getElementById('stamped')?.hasAttribute('nonce')).toBe(false);
});

it('rejects missing, duplicate, tampered, reordered, and unadvertised nonce markers', () => {
  document.head.innerHTML = nonceHead('activeScript', 'activeStyle');
  for (const head of [
    nonceHead('newScript', 'newStyle').replace('content="1"', 'content="0"'),
    nonceHead('newScript', 'newScript'),
    nonceHead('newStyle', 'newScript'),
    nonceHead('newScript', ''),
    nonceHead('newScript', 'newStyle').replace(
      /<meta name="interact-navigation-nonce" content="1"[^>]*>/,
      '',
    ),
    nonceHead('wrongScript', 'newStyle'),
  ])
    expect(() =>
      incomingDocument(
        '<!doctype html>' + parsed(head).documentElement.outerHTML,
        document,
        ['newScript', 'newStyle'],
      ),
    ).toThrow();
  expect(() =>
    incomingDocument(
      '<!doctype html>' +
        parsed(nonceHead('newScript', 'newStyle')).documentElement.outerHTML,
      document,
    ),
  ).toThrow();
  for (const nonceCount of [-1, 0.5, '2']) {
    document.head.innerHTML = `<meta name="interact-navigation" content='${JSON.stringify({ ...identity, nonceCount })}'>`;
    expect(documentIdentity(document)).toBeUndefined();
  }
});

it('preserves comments and raw text, decodes nonce attributes natively, and removes temporary parser metadata', () => {
  document.head.innerHTML = nonceHead('activeScript', 'activeStyle');
  const raw = '<style nonce="newStyle">inside a string</style>';
  const source =
    '<!doctype html><html lang="fr"><head>' +
    nonceHead(
      'newScript',
      'newStyle',
      '<!-- ' +
        raw +
        ' --><style data-note="a > b" nonce=\'new&#83;tyle\'>.safe{color:red}</style><script type="application/json">' +
        JSON.stringify(raw) +
        '</script>',
    ) +
    '</head><body class="page"><textarea>' +
    raw +
    '</textarea><p>Safe</p></body></html>';
  const next = incomingDocument(source, document, ['newScript', 'newStyle']);
  expect(next.documentElement.lang).toBe('fr');
  expect(next.body.className).toBe('page');
  expect(next.querySelector('style')?.nonce).toBe('activeStyle');
  expect(next.querySelector('style')?.getAttribute('data-note')).toBe('a > b');
  expect(next.querySelector('script')?.textContent).toBe(JSON.stringify(raw));
  expect(next.querySelector('textarea')?.textContent).toBe(raw);
  expect(next.head.innerHTML).toContain('<!-- ' + raw + ' -->');
  expect(next.documentElement.outerHTML).not.toContain('data-interact-nonce-');
});
