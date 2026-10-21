import { afterEach, beforeEach, expect, it, vi } from 'vite-plus/test';
import { PageAssets } from '../../resources/ts/navigation/assets';
import { PageFetcher } from '../../resources/ts/navigation/prefetch';
import {
  NavigationHistory,
  pageEntry,
} from '../../resources/ts/navigation/history';
import type { Fetcher } from '../../resources/ts/types';

beforeEach(() => {
  document.head.innerHTML = '';
  window.history.replaceState({ app: 42 }, '', '/start');
});
afterEach(() => {
  document.head.innerHTML = '';
  vi.restoreAllMocks();
  vi.useRealTimers();
});
function parsed(head: string): Document {
  return new DOMParser().parseFromString(
    `<!doctype html><html><head>${head}</head><body></body></html>`,
    'text/html',
  );
}
function response(cache = '', html = '<html>Page</html>'): Response {
  return new Response(html, {
    headers: { 'Content-Type': 'text/html', 'Cache-Control': cache },
  });
}

it('loads styles before activating them and runs each new external script only once', async () => {
  document.head.innerHTML =
    '<script src="/runtime.js"></script><meta name="old" content="stale">';
  const assets = new PageAssets(document);
  const appended: Element[] = [];
  const original = document.head.append.bind(document.head);
  vi.spyOn(document.head, 'append').mockImplementation((...nodes) => {
    original(...nodes);
    for (const node of nodes)
      if (
        node instanceof HTMLLinkElement ||
        (node instanceof HTMLScriptElement && node.hasAttribute('src'))
      ) {
        appended.push(node);
        queueMicrotask(() => node.dispatchEvent(new Event('load')));
      }
  });
  const next = parsed(
    '<title>Destination</title><meta name="description" content="new"><link rel="stylesheet" href="style.css"><script src="/runtime.js"></script><script type="module" src="module.js"></script><script type="application/json">{"data":1}</script><style>main {color:red}</style>',
  );
  const signal = new AbortController().signal;
  const activate = await assets.prepare(
    next,
    new URL('/area/page', window.location.href).href,
    signal,
  );
  expect(appended).toHaveLength(2);
  const stylesheet = document.querySelector('link');
  expect(stylesheet?.media).toBe('not all');
  activate();
  expect(stylesheet?.media).toBe('');
  expect(
    document.querySelector('script[type="module"]')?.getAttribute('src'),
  ).toBe(new URL('/area/module.js', window.location.href).href);
  assets.head(next);
  expect(document.title).toBe('Destination');
  expect(document.querySelector('meta[name="old"]')).toBeNull();
  expect(
    document.querySelector('meta[name="description"]')?.getAttribute('content'),
  ).toBe('new');
  await assets.prepare(
    next,
    new URL('/area/page', window.location.href).href,
    signal,
  );
  assets.head(next);
  expect(appended).toHaveLength(2);
  expect(document.querySelectorAll('style')).toHaveLength(1);
  expect(document.querySelectorAll('script[src="/runtime.js"]')).toHaveLength(
    1,
  );
});

it('rejects executable inline scripts and failed assets without leaving staged styles behind', async () => {
  const assets = new PageAssets(document);
  for (const type of [
    'application/ecmascript',
    'text/javascript1.5',
    'text/jscript',
    'importmap',
    'speculationrules',
  ]) {
    await expect(
      assets.prepare(
        parsed(`<script type="${type}">application code</script>`),
        window.location.href,
        new AbortController().signal,
      ),
    ).rejects.toThrow('inline');
  }
  const signal = new AbortController().signal;
  await expect(
    assets.prepare(
      parsed('<script>alert(1)</script>'),
      window.location.href,
      signal,
    ),
  ).rejects.toThrow('inline');
  const original = document.head.append.bind(document.head);
  vi.spyOn(document.head, 'append').mockImplementation((...nodes) => {
    original(...nodes);
    for (const node of nodes)
      if (node instanceof Element)
        queueMicrotask(() => node.dispatchEvent(new Event('error')));
  });
  await expect(
    assets.prepare(
      parsed('<link rel="stylesheet" href="/broken.css">'),
      window.location.href,
      signal,
    ),
  ).rejects.toThrow('failed');
  expect(document.querySelector('link')).toBeNull();
  await expect(
    assets.prepare(
      parsed('<script src="javascript:alert(1)"></script>'),
      window.location.href,
      signal,
    ),
  ).rejects.toThrow('destination');
});

it('aborts pending assets and times out unresponsive loads', async () => {
  vi.useFakeTimers();
  const assets = new PageAssets(document);
  const controller = new AbortController();
  const pending = assets.prepare(
    parsed('<script src="/slow.js"></script>'),
    window.location.href,
    controller.signal,
  );
  const aborted = expect(pending).rejects.toMatchObject({ name: 'AbortError' });
  controller.abort();
  await aborted;
  expect(document.querySelector('script')).toBeNull();
  const timeout = expect(
    assets.prepare(
      parsed('<script src="/timeout.js"></script>'),
      window.location.href,
      new AbortController().signal,
    ),
  ).rejects.toThrow('failed');
  await vi.advanceTimersByTimeAsync(15000);
  await timeout;
  const canceled = new AbortController();
  canceled.abort();
  await expect(
    assets.prepare(
      parsed('<link rel="stylesheet" href="/canceled.css">'),
      window.location.href,
      canceled.signal,
    ),
  ).rejects.toMatchObject({ name: 'AbortError' });
  await expect(
    assets.prepare(
      parsed('<link rel="stylesheet" href="javascript:alert(1)">'),
      window.location.href,
      new AbortController().signal,
    ),
  ).rejects.toThrow('stylesheet');
});

it('joins in-flight prefetches, consumes explicitly fresh responses once, and honors cache policy', async () => {
  vi.useFakeTimers();
  const fetcher = vi.fn<Fetcher>(async () => response('private, max-age=60'));
  const pages = new PageFetcher(fetcher);
  const url = new URL('/prefetch', window.location.href).href;
  pages.prefetch(url);
  pages.prefetch(url);
  await vi.waitFor(() => expect(fetcher).toHaveBeenCalledOnce());
  const first = await pages.take(url, new AbortController().signal);
  expect(first.cacheUntil).toBe(Date.now() + 30000);
  expect(fetcher).toHaveBeenCalledOnce();
  await pages.take(url, new AbortController().signal);
  expect(fetcher).toHaveBeenCalledTimes(2);
  for (const policy of ['no-store, max-age=60', 'no-cache, max-age=60', '']) {
    fetcher.mockResolvedValueOnce(response(policy));
    pages.prefetch(url);
    await vi.runAllTimersAsync();
    await pages.take(url, new AbortController().signal);
  }
  expect(fetcher).toHaveBeenCalledTimes(8);
  pages.clear();
});

it('expires and bounds prefetch entries and rejects non-HTML or cross-origin responses', async () => {
  vi.useFakeTimers();
  const fetcher = vi.fn<Fetcher>(async () => response('max-age=1'));
  const pages = new PageFetcher(fetcher);
  const url = new URL('/prefetch', window.location.href).href;
  pages.prefetch(url);
  await vi.runAllTimersAsync();
  await vi.advanceTimersByTimeAsync(1001);
  await pages.take(url, new AbortController().signal);
  expect(fetcher).toHaveBeenCalledTimes(2);
  for (let index = 0; index < 10; index++)
    pages.prefetch(new URL(`/bounded-${index}`, window.location.href).href);
  pages.clear();
  for (const invalid of [
    new Response('no', { status: 403 }),
    new Response('json', { headers: { 'Content-Type': 'application/json' } }),
    new Response('download', {
      headers: {
        'Content-Type': 'text/html',
        'Content-Disposition': 'attachment',
      },
    }),
  ]) {
    fetcher.mockResolvedValueOnce(invalid);
    await expect(pages.take(url, new AbortController().signal)).rejects.toThrow(
      'HTML',
    );
  }
  const cross = response();
  Object.defineProperty(cross, 'url', {
    value: 'https://external.example/page',
  });
  fetcher.mockResolvedValueOnce(cross);
  await expect(pages.take(url, new AbortController().signal)).rejects.toThrow(
    'origin',
  );
  fetcher.mockRejectedValueOnce(new Error('offline'));
  pages.prefetch(url);
  await vi.runAllTimersAsync();
  fetcher.mockResolvedValueOnce(response());
  await expect(
    pages.take(url, new AbortController().signal),
  ).resolves.toHaveProperty('html');
});

it('aborts a prefetched request when its consuming visit is superseded', async () => {
  let requestSignal: AbortSignal | null | undefined;
  const fetcher: Fetcher = async (_url, init) => {
    requestSignal = init.signal;
    return new Promise((_resolve, reject) =>
      init.signal?.addEventListener('abort', () =>
        reject(new DOMException('Aborted', 'AbortError')),
      ),
    );
  };
  const pages = new PageFetcher(fetcher);
  const url = new URL('/prefetch', window.location.href).href;
  pages.prefetch(url);
  const controller = new AbortController();
  const taken = pages.take(url, controller.signal);
  const rejected = expect(taken).rejects.toMatchObject({ name: 'AbortError' });
  controller.abort();
  await rejected;
  expect(requestSignal?.aborted).toBe(true);
  pages.clear();
});

it('keeps namespaced page and query history separate without losing application state', () => {
  const restore = vi.fn();
  const history = new NavigationHistory(window, restore);
  const initial = { ...history.current };
  history.query(new URL('/start?q=one', window.location.href), true);
  expect(history.current.page).toBe(initial.page);
  expect(history.current.index).toBe(1);
  expect(window.history.state).toMatchObject({ app: 42 });
  history.commit(new URL('/second', window.location.href), false);
  expect(history.current.page).not.toBe(initial.page);
  expect(history.current.index).toBe(2);
  const destination = { page: initial.page, index: 1, x: 50, y: 100 };
  history.commit(
    new URL('/start?q=one', window.location.href),
    false,
    destination,
  );
  expect(history.current).toEqual(destination);
  history.acceptQuery(initial);
  expect(history.current).toEqual(initial);
  window.dispatchEvent(new PopStateEvent('popstate', { state: { app: 42 } }));
  expect(restore).toHaveBeenCalledWith(undefined);
  const go = vi.spyOn(window.history, 'go').mockImplementation(() => {});
  history.cancel(destination);
  expect(go).toHaveBeenCalledWith(-1);
  window.dispatchEvent(
    new PopStateEvent('popstate', { state: { interactNavigation: initial } }),
  );
  expect(restore).toHaveBeenCalledOnce();
  window.dispatchEvent(new Event('scroll'));
  history.dispose();
  expect(
    pageEntry({ interactNavigation: { page: '', index: 0, x: 0, y: 0 } }),
  ).toBeUndefined();
  expect(
    pageEntry({ interactNavigation: { ...initial, y: Infinity } }),
  ).toBeUndefined();
  expect(pageEntry(null)).toBeUndefined();
});

it('removes prepared but uncommitted styles when a newer visit takes ownership', async () => {
  const assets = new PageAssets(document);
  const original = document.head.append.bind(document.head);
  vi.spyOn(document.head, 'append').mockImplementation((...nodes) => {
    original(...nodes);
    for (const node of nodes)
      if (node instanceof HTMLLinkElement)
        queueMicrotask(() => node.dispatchEvent(new Event('load')));
  });
  const controller = new AbortController();
  await assets.prepare(
    parsed('<link rel="stylesheet" href="/abandoned.css">'),
    window.location.href,
    controller.signal,
  );
  expect(document.querySelector('link')?.media).toBe('not all');
  controller.abort();
  expect(document.querySelector('link')).toBeNull();
});

it('starts a fresh hover request after an explicitly cached prefetch expires', async () => {
  vi.useFakeTimers();
  const fetcher = vi.fn<Fetcher>(async () => response('max-age=1'));
  const pages = new PageFetcher(fetcher);
  const url = new URL('/expire', window.location.href).href;
  pages.prefetch(url);
  await vi.runAllTimersAsync();
  await vi.advanceTimersByTimeAsync(1001);
  pages.prefetch(url);
  expect(fetcher).toHaveBeenCalledTimes(2);
  expect(fetcher.mock.calls[1]?.[1]).toMatchObject({
    cache: 'no-store',
    credentials: 'same-origin',
  });
  pages.clear();
});

it('claims native fragment entries with cleared state once and preserves the requested fragment after fetch', async () => {
  const restore = vi.fn();
  const history = new NavigationHistory(window, restore);
  const initial = { ...history.current };
  window.history.pushState(null, '', '/start#target');
  window.dispatchEvent(new PopStateEvent('popstate', { state: null }));
  window.dispatchEvent(new HashChangeEvent('hashchange'));
  expect(history.current).toMatchObject({ page: initial.page, index: 1 });
  expect(pageEntry(window.history.state)).toEqual(history.current);
  expect(restore).toHaveBeenCalledOnce();
  history.commit(new URL('/second', window.location.href), false);
  const go = vi.spyOn(window.history, 'go').mockImplementation(() => {});
  history.cancel(initial);
  expect(go).toHaveBeenCalledWith(2);
  history.dispose();

  const fetched = response();
  Object.defineProperty(fetched, 'url', {
    value: new URL('/page', window.location.href).href,
  });
  const pages = new PageFetcher(async () => fetched);
  const result = await pages.take(
    new URL('/page#target', window.location.href).href,
    new AbortController().signal,
  );
  expect(new URL(result.url).hash).toBe('#target');
});
