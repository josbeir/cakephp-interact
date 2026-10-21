import { afterEach, beforeEach, expect, it, vi } from 'vite-plus/test';
import { destroyBindings, mountClient } from './mount';
import type { IslandClient } from '../../resources/ts/client';
import type { Fetcher, IslandResponse } from '../../resources/ts/types';
import { readMetadata } from '../../resources/ts/transport/metadata';
import type { UrlFields } from '../../resources/ts/url/types';
import { LoadTrigger } from '../../resources/ts/loading/trigger';

const loadObservers: LoadObserver[] = [];
/**
 * Delivers controlled viewport transitions without depending on a layout engine.
 */
class LoadObserver implements IntersectionObserver {
  readonly root = null;
  readonly rootMargin = '0px';
  readonly scrollMargin = '0px';
  readonly thresholds = [0];
  readonly delay = 0;
  readonly trackVisibility = false;
  readonly observe = vi.fn();
  readonly unobserve = vi.fn();
  readonly disconnect = vi.fn();
  readonly takeRecords = (): IntersectionObserverEntry[] => [];

  /**
   * Retain the browser callback so tests can deliver intersection changes.
   */
  constructor(private readonly callback: IntersectionObserverCallback) {
    loadObservers.push(this);
  }

  /**
   * Publish a single element's viewport state using complete native entry metadata.
   */
  emit(target: Element, visible: boolean): void {
    this.callback(
      [
        {
          target,
          isIntersecting: visible,
          intersectionRatio: visible ? 1 : 0,
          boundingClientRect: target.getBoundingClientRect(),
          intersectionRect: target.getBoundingClientRect(),
          rootBounds: null,
          time: performance.now(),
        },
      ],
      this,
    );
  }
}

const clients: IslandClient[] = [];
const fields: UrlFields = {
  query: { name: 'q', type: 'string', default: '', history: false },
};
function root(mode = 'manual'): HTMLElement {
  const element = document.createElement('div');
  Object.assign(element.dataset, {
    interactId: 'lazy',
    interactSnapshot: 'pending',
    interactState: '{}',
    interactWritable: '{}',
    interactEndpoint: '/updates',
    interactCsrf: 'csrf',
    interactLoading: mode,
    interactUrl: JSON.stringify(fields),
  });
  element.innerHTML = '<button @click="$server.sync()">Load</button>';
  document.body.append(element);
  return element;
}
function reply(query = '', changes: Partial<IslandResponse> = {}): Response {
  const payload: IslandResponse = {
    version: 1,
    id: 'lazy',
    snapshot: 'mounted',
    html: '<div data-interact-id="lazy"><input aria-label="Query" x-interact:model="query"><p x-text="$server.state.count"></p></div>',
    state: { query, count: 4 },
    writable: { query: { type: 'string', nullable: false } },
    errors: {},
    events: [],
    url: fields,
    ...changes,
  };
  return new Response(JSON.stringify(payload), {
    headers: { 'Content-Type': 'application/json' },
  });
}
function body(options: RequestInit | undefined): unknown {
  if (typeof options?.body !== 'string')
    throw new Error('Expected JSON request.');
  const parsed: unknown = JSON.parse(options.body);
  return parsed;
}
function setup(fetcher: Fetcher = async () => reply(), mode = 'manual') {
  const element = root(mode);
  const fetch = vi.fn(fetcher);
  const client = mountClient(element, fetch);
  clients.push(client);
  return { element, client, fetch };
}
beforeEach(() => {
  vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('visible');
});
afterEach(() => {
  for (const client of clients.splice(0)) {
    destroyBindings(client.root);
    client.dispose();
  }
  document.body.innerHTML = '';
  history.replaceState(null, '', '/');
  vi.restoreAllMocks();
  loadObservers.splice(0);
  vi.unstubAllGlobals();
  vi.useRealTimers();
});

it('loads once on explicit sync and immediately enables real models and actions', async () => {
  const { client, fetch, element } = setup();
  expect(client.bridge.state).toEqual({});
  expect(client.bridge.dirty).toBe(false);
  expect(fetch).not.toHaveBeenCalled();
  expect(await client.bridge.sync()).toEqual({ ok: true });
  expect(body(fetch.mock.calls[0]?.[1])).toMatchObject({
    initialize: true,
    updates: {},
    action: null,
    args: [],
  });
  expect(client.bridge.state).toEqual({ query: '', count: 4 });
  expect(element.querySelector('input')?.value).toBe('');
  expect(await client.bridge.call('increment')).toEqual({ ok: true });
  expect(body(fetch.mock.calls[1]?.[1])).not.toHaveProperty('initialize');
});

it('coalesces overlapping explicit triggers and keeps activity on the normal bridge', async () => {
  let complete: ((response: Response) => void) | undefined;
  const { client, fetch } = setup(
    () =>
      new Promise((resolve) => {
        complete = resolve;
      }),
  );
  const one = client.bridge.sync();
  const two = client.bridge.sync();
  expect(client.bridge.busy).toBe(true);
  expect(client.bridge.pending(null)).toBe(true);
  expect(one).toBe(two);
  expect(fetch).toHaveBeenCalledOnce();
  complete?.(reply());
  await one;
  expect(client.bridge.pending()).toBe(false);
});

it('blocks pre-mount actions and directives without sending a request', async () => {
  const { client, fetch } = setup();
  expect((await client.bridge.call('increment')).ok).toBe(false);
  expect(fetch).not.toHaveBeenCalled();
  const input = document.createElement('input');
  expect(() =>
    client.bindCustomModel(
      input,
      { path: 'query', timing: 'defer', delay: 250 },
      { read: () => '', write: () => undefined },
    ),
  ).toThrow('loaded island');
  expect(() =>
    client.bindModel(input, { path: 'query', timing: 'defer', delay: 150 }),
  ).toThrow('loaded island');
  expect(() =>
    client.bindPoll(input, { interval: 1000, visible: false }, () => undefined),
  ).toThrow('loaded island');
});

it('retries explicitly after a network failure without automatic replay', async () => {
  const { client, fetch } = setup();
  fetch.mockRejectedValueOnce(new Error('Offline'));
  expect((await client.bridge.sync()).ok).toBe(false);
  expect(client.bridge.error?.code).toBe('network_error');
  expect(client.bridge.paused).toBe(true);
  await Promise.resolve();
  expect(fetch).toHaveBeenCalledOnce();
  expect(await client.bridge.sync()).toEqual({ ok: true });
  expect(client.bridge.error).toBeNull();
});

it('does not retry authorization failures through sync', async () => {
  const { client, fetch } = setup(
    async () => new Response('', { status: 403 }),
  );
  await client.bridge.sync();
  expect(client.bridge.error?.code).toBe('forbidden');
  await client.bridge.sync();
  expect(fetch).toHaveBeenCalledOnce();
});

it('defers until startup and cancels automatic work when removed', async () => {
  const { client, fetch } = setup(undefined, 'defer');
  expect(fetch).not.toHaveBeenCalled();
  await vi.waitFor(() => expect(client.bridge.state.count).toBe(4));
  expect(fetch).toHaveBeenCalledOnce();
  client.dispose();
  const other = setup(undefined, 'defer');
  other.client.dispose();
  await Promise.resolve();
  expect(other.fetch).not.toHaveBeenCalled();
});

it('ignores late initialization responses after disposal', async () => {
  let complete: ((response: Response) => void) | undefined;
  const { client, fetch, element } = setup(
    () =>
      new Promise((resolve) => {
        complete = resolve;
      }),
  );
  const pending = client.bridge.sync();
  client.dispose();
  complete?.(reply());
  expect((await pending).ok).toBe(false);
  expect(client.bridge.state).toEqual({});
  expect(element.querySelector('input')).toBeNull();
  expect(fetch.mock.calls[0]?.[1].signal?.aborted).toBe(true);
});

it('reserves URL ownership without canonicalizing or mounting on navigation', async () => {
  history.replaceState({ application: true }, '', '/?q=original');
  const { client, fetch } = setup(async (_url, init) => {
    const payload = body(init);
    expect(payload).toMatchObject({ pageQuery: '?q=latest' });
    return reply('latest');
  });
  await Promise.resolve();
  expect(location.search).toBe('?q=original');
  history.replaceState(history.state, '', '/?q=latest');
  window.dispatchEvent(new PopStateEvent('popstate'));
  expect(fetch).not.toHaveBeenCalled();
  await client.bridge.sync();
  expect(client.bridge.state.query).toBe('latest');
  expect(history.state).toEqual({ application: true });
});

it('restores newer history after loading without another initialization', async () => {
  history.replaceState(null, '', '/?q=first');
  let complete: ((response: Response) => void) | undefined;
  const { client, fetch } = setup(
    () =>
      new Promise((resolve) => {
        complete = resolve;
      }),
  );
  const pending = client.bridge.sync();
  history.replaceState(null, '', '/?q=newer');
  window.dispatchEvent(new PopStateEvent('popstate'));
  fetch.mockResolvedValueOnce(reply('newer'));
  complete?.(reply('first'));
  await pending;
  await vi.waitFor(() => expect(client.bridge.state.query).toBe('newer'));
  expect(location.search).toBe('?q=newer');
  expect(fetch).toHaveBeenCalledTimes(2);
  expect(body(fetch.mock.calls[1]?.[1])).toMatchObject({
    urlRestore: true,
    updates: { query: 'newer' },
  });
  expect(body(fetch.mock.calls[1]?.[1])).not.toHaveProperty('initialize');
});

it.each(['later', ''])(
  'rejects invalid generated loading metadata %j',
  (mode) => {
    expect(() => readMetadata(root(mode))).toThrow('loading mode');
  },
);
it.each(['interactState', 'interactWritable', 'interactUploads'])(
  'rejects active data inside pending metadata: %s',
  (name) => {
    const element = root();
    element.dataset[name] =
      name === 'interactState'
        ? '{"query":"bad"}'
        : name === 'interactWritable'
          ? '{"query":{"type":"string","nullable":false}}'
          : '{"image":{"multiple":false,"maxSize":1,"maxFiles":1,"files":[]}}';
    expect(() => readMetadata(element)).toThrow('initial island state');
  },
);

it('falls back to deferred loading without IntersectionObserver and waits for document visibility', async () => {
  vi.stubGlobal('IntersectionObserver', undefined);
  const visibility = vi
    .spyOn(document, 'visibilityState', 'get')
    .mockReturnValue('hidden');
  const element = root();
  const load = vi.fn(async () => undefined);
  const trigger = new LoadTrigger(element, 'lazy', load);
  await Promise.resolve();
  expect(load).not.toHaveBeenCalled();
  visibility.mockReturnValue('visible');
  document.dispatchEvent(new Event('visibilitychange'));
  expect(load).toHaveBeenCalledOnce();
  document.dispatchEvent(new Event('visibilitychange'));
  expect(load).toHaveBeenCalledOnce();
  trigger.dispose();
});

it('observes only its own viewport entry, loads once, and disconnects', async () => {
  vi.stubGlobal('IntersectionObserver', LoadObserver);
  const element = root();
  const load = vi.fn(async () => undefined);
  const trigger = new LoadTrigger(element, 'lazy', load);
  const observer = loadObservers[0];
  expect(observer?.observe).toHaveBeenCalledWith(element);
  observer?.emit(document.body, true);
  observer?.emit(element, false);
  await Promise.resolve();
  expect(load).not.toHaveBeenCalled();
  observer?.emit(element, true);
  expect(load).toHaveBeenCalledOnce();
  expect(observer?.disconnect).toHaveBeenCalledOnce();
  observer?.emit(element, true);
  expect(load).toHaveBeenCalledOnce();
  trigger.dispose();
});

it('does not load a detached placeholder or revive a disposed observer', async () => {
  vi.stubGlobal('IntersectionObserver', LoadObserver);
  const element = root();
  const load = vi.fn(async () => undefined);
  const trigger = new LoadTrigger(element, 'lazy', load);
  element.remove();
  loadObservers[0]?.emit(element, true);
  await Promise.resolve();
  expect(load).not.toHaveBeenCalled();
  trigger.dispose();
  document.body.append(element);
  loadObservers[0]?.emit(element, true);
  expect(load).not.toHaveBeenCalled();
});

it('leaves initialization available after an invalid render response', async () => {
  const { client, fetch } = setup();
  fetch.mockResolvedValueOnce(reply('', { url: {} }));
  expect((await client.bridge.sync()).ok).toBe(false);
  expect(client.bridge.error?.code).toBe('invalid_response');
  expect(client.bridge.state).toEqual({});
});

it('initializes declared upload handles before Alpine binds newly loaded file controls', async () => {
  const element = root();
  const metadata = {
    photo: { multiple: false, maxSize: 1024, maxFiles: 1, files: [] },
  };
  const upload = vi.fn(async () =>
    reply('', {
      uploads: {
        photo: {
          ...metadata.photo,
          files: [{ id: 'a'.repeat(32), name: 'picture.txt', size: 5 }],
        },
      },
    }),
  );
  const client = mountClient(
    element,
    async () =>
      reply('', {
        uploads: metadata,
        html: '<div data-interact-id="lazy"><input type="file" aria-label="Photo" x-interact:model="photo"></div>',
      }),
    undefined,
    upload,
  );
  clients.push(client);
  expect(await client.bridge.sync()).toEqual({ ok: true });
  expect(client.bridge.uploads.photo?.files).toEqual([]);
  const input = element.querySelector('input');
  if (!input) throw new Error('Missing loaded file control.');
  Object.defineProperty(input, 'files', {
    value: [new File(['hello'], 'picture.txt', { type: 'text/plain' })],
  });
  input.dispatchEvent(new Event('change', { bubbles: true }));
  await vi.waitFor(() =>
    expect(client.bridge.uploads.photo?.files[0]?.status).toBe('ready'),
  );
  expect(upload).toHaveBeenCalledOnce();
});

it('rolls back activation and upload handles if applying loaded markup fails', async () => {
  const element = root();
  const client = mountClient(
    element,
    async () =>
      reply('', {
        uploads: {
          photo: { multiple: false, maxSize: 1024, maxFiles: 1, files: [] },
        },
      }),
    () => {
      throw new Error('Patch failed');
    },
  );
  clients.push(client);
  expect((await client.bridge.sync()).ok).toBe(false);
  expect(client.bridge.state).toEqual({});
  expect(client.bridge.uploads).toEqual({});
  expect(client.bridge.error?.code).toBe('client_error');
});
