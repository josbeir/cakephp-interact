import { afterEach, beforeEach, expect, it, vi } from 'vite-plus/test';
import { mercureTransport } from '../../resources/ts/realtime/mercure';
import type { RealtimeGrant } from '../../resources/ts/realtime/types';

const close: (() => void)[] = [];
const grant: RealtimeGrant = {
  driver: 'mercure',
  data: {
    url: 'http://hub.test/feed',
    token: 'subscriber-only',
    topics: ['records/1'],
  },
  expires: 9999999999,
};

beforeEach(() => {
  vi.useFakeTimers();
  vi.spyOn(Math, 'random').mockReturnValue(0.5);
});
afterEach(() => {
  close.splice(0).forEach((dispose) => dispose());
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
  vi.useRealTimers();
});

function stream() {
  let feed: ReadableStreamDefaultController<Uint8Array> | undefined;
  const body = new ReadableStream<Uint8Array>({
    start(controller) {
      feed = controller;
    },
  });
  return {
    response: new Response(body, {
      headers: { 'Content-Type': 'text/event-stream' },
    }),
    feed,
  };
}

it('uses Mercure 1.x matchers, bearer headers, bounded SSE decoding, and resume cursors', async () => {
  const first = stream();
  const next = stream();
  const fetcher = vi
    .fn<typeof fetch>()
    .mockResolvedValueOnce(first.response)
    .mockResolvedValue(next.response);
  vi.stubGlobal('fetch', fetcher);
  const callbacks = { receive: vi.fn(), status: vi.fn() };
  close.push(mercureTransport(grant, callbacks));
  await vi.advanceTimersByTimeAsync(0);
  const request = fetcher.mock.calls[0]?.[0];
  expect(request).toBeInstanceOf(URL);
  if (!(request instanceof URL))
    throw new Error('Missing Mercure request URL.');
  expect(request.searchParams.get('match')).toBe('records/1');
  expect(fetcher.mock.calls[0]?.[1]).toMatchObject({
    credentials: 'omit',
    redirect: 'error',
    headers: { Authorization: 'Bearer subscriber-only' },
  });
  first.feed?.enqueue(
    new TextEncoder().encode('id: saved-cursor\ndata: {"version":1}\n\n'),
  );
  await vi.advanceTimersByTimeAsync(0);
  expect(callbacks.receive).toHaveBeenCalledWith({ version: 1 });
  first.feed?.close();
  await vi.advanceTimersByTimeAsync(500);
  expect(
    fetcher.mock.calls[1]?.[0] instanceof URL
      ? fetcher.mock.calls[1][0].href
      : '',
  ).toContain('last_event_id=saved-cursor');
  expect(callbacks.status).toHaveBeenCalledWith('reconnecting');
  close[0]?.();
  next.feed?.close();
  await vi.advanceTimersByTimeAsync(30000);
  expect(fetcher).toHaveBeenCalledTimes(2);
});

it.each([
  { ...grant, data: {} },
  { ...grant, data: { ...grant.data, topics: ['*'] } },
  { ...grant, data: { ...grant.data, url: 'file:///feed' } },
  {
    ...grant,
    data: { ...grant.data, url: 'https://user:secret@hub.test/feed' },
  },
])(
  'rejects unsafe connection configuration before contacting a provider',
  (invalid) => {
    vi.stubGlobal('fetch', vi.fn());
    expect(() =>
      mercureTransport(invalid, { receive: vi.fn(), status: vi.fn() }),
    ).toThrow();
    expect(fetch).not.toHaveBeenCalled();
  },
);

it.each([
  new Response('', { status: 401 }),
  new Response('not SSE'),
  new Response(null, { headers: { 'Content-Type': 'text/event-stream' } }),
])(
  'reports terminal authorization or protocol failures without a reconnect loop',
  async (response) => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => response),
    );
    const status = vi.fn();
    close.push(mercureTransport(grant, { receive: vi.fn(), status }));
    await vi.advanceTimersByTimeAsync(10000);
    expect(status).toHaveBeenLastCalledWith(
      'error',
      'Realtime connection failed.',
    );
    expect(fetch).toHaveBeenCalledOnce();
  },
);

it.each(['not JSON', '[]'])(
  'rejects malformed SSE payloads and closes its reader',
  async (data) => {
    const current = stream();
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => current.response),
    );
    const status = vi.fn();
    close.push(mercureTransport(grant, { receive: vi.fn(), status }));
    await vi.advanceTimersByTimeAsync(0);
    current.feed?.enqueue(new TextEncoder().encode(`data: ${data}\n\n`));
    await vi.advanceTimersByTimeAsync(0);
    expect(status).toHaveBeenLastCalledWith(
      'error',
      'Realtime connection failed.',
    );
  },
);

it('backs off network/server failures and cancels the pending reconnect on disposal', async () => {
  const fetcher = vi
    .fn<typeof fetch>()
    .mockResolvedValueOnce(new Response('', { status: 503 }))
    .mockRejectedValue(new TypeError('offline'));
  vi.stubGlobal('fetch', fetcher);
  const dispose = mercureTransport(grant, {
    receive: vi.fn(),
    status: vi.fn(),
  });
  close.push(dispose);
  await vi.advanceTimersByTimeAsync(1500);
  expect(fetcher).toHaveBeenCalledTimes(3);
  dispose();
  await vi.advanceTimersByTimeAsync(30000);
  expect(fetcher).toHaveBeenCalledTimes(3);
});

it('accepts frames without SSE cursors and closes an aborted reader without reconnecting', async () => {
  const current = stream();
  const fetcher = vi.fn<typeof fetch>(async (_url, options) => {
    options?.signal?.addEventListener('abort', () => current.feed?.close(), {
      once: true,
    });
    return current.response;
  });
  vi.stubGlobal('fetch', fetcher);
  const callbacks = { receive: vi.fn(), status: vi.fn() };
  const dispose = mercureTransport(grant, callbacks);
  close.push(dispose);
  await vi.advanceTimersByTimeAsync(0);
  current.feed?.enqueue(new TextEncoder().encode('data: {"version":1}\n\n'));
  await vi.advanceTimersByTimeAsync(0);
  expect(callbacks.receive).toHaveBeenCalledWith({ version: 1 });
  dispose();
  await vi.advanceTimersByTimeAsync(30000);
  expect(callbacks.status).toHaveBeenLastCalledWith('connected');
  expect(fetcher).toHaveBeenCalledOnce();
});

it('cancels a pending connection attempt without reporting an error or retrying', async () => {
  const fetcher = vi.fn<typeof fetch>(
    (_url, options) =>
      new Promise<Response>((_resolve, reject) => {
        options?.signal?.addEventListener(
          'abort',
          () => reject(new DOMException('Aborted', 'AbortError')),
          { once: true },
        );
      }),
  );
  vi.stubGlobal('fetch', fetcher);
  const callbacks = { receive: vi.fn(), status: vi.fn() };
  const dispose = mercureTransport(grant, callbacks);
  close.push(dispose);
  dispose();
  await vi.advanceTimersByTimeAsync(30000);
  expect(fetcher).toHaveBeenCalledOnce();
  expect(fetcher.mock.calls[0]?.[1]?.signal?.aborted).toBe(true);
  expect(callbacks.status).not.toHaveBeenCalled();
});
