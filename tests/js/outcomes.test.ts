import { afterEach, expect, it, vi } from 'vite-plus/test';
import {
  BrowserEffects,
  destination,
} from '../../resources/ts/effects/browser';
import { mountClient, destroyBindings } from './mount';
import type { IslandClient } from '../../resources/ts/client';
import type { Fetcher, IslandResponse } from '../../resources/ts/types';

const clients: IslandClient[] = [];
const initial = { title: 'draft', count: 0 };
function setup(fetcher: Fetcher, patch = vi.fn()) {
  const root = document.createElement('section');
  Object.assign(root.dataset, {
    interactId: 'outcome',
    interactSnapshot: 'signed',
    interactState: JSON.stringify(initial),
    interactWritable: JSON.stringify({
      title: { type: 'string', nullable: false },
    }),
    interactEndpoint: '/update',
  });
  root.innerHTML = '<p>Original</p>';
  document.body.append(root);
  const client = mountClient(root, fetcher, patch);
  clients.push(client);
  return { client, root, patch };
}
function result(value: unknown, status = 200): Response {
  return new Response(JSON.stringify(value), {
    status,
    headers: { 'Content-Type': 'application/json' },
  });
}
function render(extra: Partial<IslandResponse> = {}): IslandResponse {
  return {
    version: 1,
    id: 'outcome',
    html: '<section data-interact-id="outcome"><p>Accepted</p></section>',
    snapshot: 'next',
    state: { ...initial, count: 1 },
    writable: { title: { type: 'string', nullable: false } },
    errors: {},
    events: [],
    ...extra,
  };
}
afterEach(() => {
  for (const client of clients.splice(0)) {
    destroyBindings(client.root);
    client.dispose();
  }
  document.body.innerHTML = '';
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
  vi.useRealTimers();
});

it('accepts a terminal redirect without morphing or publishing another state', async () => {
  const navigate = vi
    .spyOn(BrowserEffects.prototype, 'navigate')
    .mockImplementation(() => {});
  const { client, root, patch } = setup(async () =>
    result({
      version: 1,
      id: 'outcome',
      redirect: { url: '/saved', replace: true },
    }),
  );
  await expect(client.bridge.call('save')).resolves.toEqual({ ok: true });
  expect(navigate).toHaveBeenCalledWith({ url: '/saved', replace: true });
  expect(patch).not.toHaveBeenCalled();
  expect(root.textContent).toBe('Original');
  expect(client.bridge.state).toEqual(initial);
  await expect(client.bridge.call('again')).resolves.toMatchObject({
    ok: false,
    error: { code: 'cancelled' },
  });
});

it('settles the redirect action and cancels queued work without sending it', async () => {
  vi.spyOn(BrowserEffects.prototype, 'navigate').mockImplementation(() => {});
  let release: (response: Response) => void = () => {};
  const fetcher = vi.fn<Fetcher>(
    () =>
      new Promise((resolve) => {
        release = resolve;
      }),
  );
  const { client } = setup(fetcher);
  const first = client.bridge.call('leave');
  const next = client.bridge.call('unsent');
  release(
    result({
      version: 1,
      id: 'outcome',
      redirect: { url: 'https://example.test', replace: false },
    }),
  );
  await expect(first).resolves.toEqual({ ok: true });
  await expect(next).resolves.toMatchObject({
    ok: false,
    error: { code: 'cancelled' },
  });
  expect(fetcher).toHaveBeenCalledOnce();
});

it('requests a download after applying state and before application events', async () => {
  const order: string[] = [];
  const download = vi
    .spyOn(BrowserEffects.prototype, 'download')
    .mockImplementation(() => {
      order.push('download');
    });
  const { client, root, patch } = setup(
    async () =>
      result(
        render({
          download: '/invoice',
          events: [{ name: 'exported', detail: {} }],
        }),
      ),
    vi.fn(() => {
      order.push('render');
    }),
  );
  root.addEventListener('exported', () => {
    order.push('event');
    expect(client.bridge.state.count).toBe(1);
  });
  await expect(client.bridge.call('download')).resolves.toEqual({ ok: true });
  expect(order).toEqual(['render', 'download', 'event']);
  expect(download).toHaveBeenCalledWith('/invoice');
  expect(patch).toHaveBeenCalledOnce();
});

it.each([
  {
    version: 1,
    id: 'outcome',
    redirect: { url: 'javascript:alert(1)', replace: false },
  },
  { version: 1, id: 'outcome', redirect: { url: '/next' } },
  { version: 1, id: 'wrong', redirect: { url: '/next', replace: false } },
  { ...render(), redirect: { url: '/next', replace: false } },
  render({ download: 'https://other.test/invoice' }),
  render({ download: '/invoice', errors: { title: ['Required.'] } }),
])(
  'rejects malformed outcomes before applying any response: %j',
  async (payload) => {
    const navigate = vi
      .spyOn(BrowserEffects.prototype, 'navigate')
      .mockImplementation(() => {});
    const download = vi
      .spyOn(BrowserEffects.prototype, 'download')
      .mockImplementation(() => {});
    const { client, patch } = setup(async () => result(payload));
    await expect(client.bridge.call('save')).resolves.toMatchObject({
      ok: false,
      error: { code: 'invalid_response' },
    });
    expect(patch).not.toHaveBeenCalled();
    expect(navigate).not.toHaveBeenCalled();
    expect(download).not.toHaveBeenCalled();
    expect(client.bridge.state).toEqual(initial);
  },
);

it.each([
  { version: 1, id: 'outcome', redirect: { url: '/next', replace: false } },
  render({ download: '/invoice' }),
])(
  'rejects navigation or downloads from passive synchronization',
  async (payload) => {
    const { client, patch } = setup(async () => result(payload));
    await expect(client.bridge.sync()).resolves.toMatchObject({
      ok: false,
      error: { code: 'invalid_response' },
    });
    expect(patch).not.toHaveBeenCalled();
  },
);

it('does not execute a terminal response returned with validation status', async () => {
  const { client } = setup(async () =>
    result(
      { version: 1, id: 'outcome', redirect: { url: '/next', replace: false } },
      422,
    ),
  );
  await expect(client.bridge.call('save')).resolves.toMatchObject({
    ok: false,
    error: { code: 'invalid_response' },
  });
});

it('ignores navigation after disposal and reports native navigation exceptions', async () => {
  const navigate = vi
    .spyOn(BrowserEffects.prototype, 'navigate')
    .mockImplementation(() => {
      throw new Error('blocked');
    });
  const response = {
    version: 1,
    id: 'outcome',
    redirect: { url: '/next', replace: false },
  };
  const { client } = setup(async () => result(response));
  await expect(client.bridge.call('save')).resolves.toMatchObject({
    ok: false,
    error: { code: 'client_error' },
  });
  expect(navigate).toHaveBeenCalledOnce();
  let release: (value: Response) => void = () => {};
  const late = setup(
    () =>
      new Promise((resolve) => {
        release = resolve;
      }),
  ).client;
  const pending = late.bridge.call('save');
  late.dispose();
  release(result(response));
  await pending;
  expect(navigate).toHaveBeenCalledOnce();
});

it('uses native history navigation and cleans up native download anchors even on failure', () => {
  const assign = vi.fn();
  const replace = vi.fn();
  const reload = vi.fn();
  vi.stubGlobal('window', {
    location: { href: 'https://example.test/current', assign, replace, reload },
  });
  const effects = new BrowserEffects();
  effects.navigate({ url: '/one', replace: false });
  effects.navigate({ url: '/two', replace: true });
  expect(assign).toHaveBeenCalledWith('/one');
  expect(replace).toHaveBeenCalledWith('/two');
  effects.navigate({ url: '/current#section', replace: false });
  expect(reload).toHaveBeenCalledOnce();
  vi.unstubAllGlobals();
  const click = vi
    .spyOn(HTMLAnchorElement.prototype, 'click')
    .mockImplementation(function (this: HTMLAnchorElement) {
      expect(this.download).toBe('');
      expect(this.getAttribute('href')).toBe('/invoice');
      expect(this.isConnected).toBe(true);
    });
  effects.download('/invoice');
  expect(document.querySelector('a')).toBeNull();
  click.mockImplementation(() => {
    throw new Error('browser blocked');
  });
  expect(() => effects.download('/invoice')).toThrow('browser blocked');
  expect(document.querySelector('a')).toBeNull();
});

it.each([
  '',
  'data:text/html,test',
  'javascript:alert(1)',
  'https://user:secret@example.test/',
  '/\\evil.test',
  '/bad\npath',
  'http://[invalid',
])('rejects unsafe destinations %s', (url) => {
  expect(destination(url)).toBe(false);
});

it('stops an existing polling timer when an action redirects', async () => {
  vi.useFakeTimers();
  vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('visible');
  vi.spyOn(BrowserEffects.prototype, 'navigate').mockImplementation(() => {});
  const { client, root } = setup(async () =>
    result({
      version: 1,
      id: 'outcome',
      redirect: { url: '/saved', replace: false },
    }),
  );
  const evaluate = vi.fn();
  const unbind = client.bindPoll(
    root,
    { interval: 100, visible: false },
    evaluate,
  );
  await vi.advanceTimersByTimeAsync(100);
  expect(evaluate).toHaveBeenCalledOnce();
  await client.bridge.call('save');
  await vi.advanceTimersByTimeAsync(1000);
  expect(evaluate).toHaveBeenCalledOnce();
  unbind();
});
