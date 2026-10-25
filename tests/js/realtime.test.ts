import Alpine from '@alpinejs/csp';
import {
  afterEach,
  beforeEach,
  describe,
  expect,
  it,
  vi,
} from 'vite-plus/test';
import { RealtimeCoordinator } from '../../resources/ts/realtime/coordinator';
import { RealtimeConnection } from '../../resources/ts/realtime/connection';
import { RealtimeIsland } from '../../resources/ts/realtime/island';
import {
  pushMessage,
  realtimeGrant,
  realtimeMetadata,
  subscriptions,
  topic,
} from '../../resources/ts/realtime/protocol';
import type {
  PushMessage,
  RealtimeFeedback,
  RealtimeMetadata,
  TransportCallbacks,
} from '../../resources/ts/realtime/types';
import type { IslandBridge } from '../../resources/ts/types';

const metadata: RealtimeMetadata = {
  lease: 'lease',
  endpoint: '/subscribe',
  subscriptions: [
    { topic: 'records/1', event: 'changed', mode: 'refresh', private: true },
  ],
};
const message: PushMessage = {
  version: 1,
  id: 'first',
  topic: 'records/1',
  event: 'changed',
  detail: { id: 1 },
};
const disposers: (() => void)[] = [];

function owner(id: string) {
  const feedback: RealtimeFeedback = {
    status: 'disabled',
    stale: false,
    error: null,
  };
  return {
    id,
    feedback,
    receive: vi.fn(),
    invalidate: vi.fn(),
  };
}

function authorization(ids: string[], changes: Record<string, unknown> = {}) {
  return new Response(
    JSON.stringify({
      version: 1,
      islands: Object.fromEntries(
        ids.map((id) => [
          id,
          { id, lease: 'renewed', subscriptions: metadata.subscriptions },
        ]),
      ),
      grant: {
        driver: 'test',
        data: {},
        expires: Math.floor(Date.now() / 1000) + 300,
      },
      ...changes,
    }),
    { headers: { 'Content-Type': 'application/json' } },
  );
}

beforeEach(() => {
  vi.useFakeTimers();
  vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('visible');
});
afterEach(() => {
  disposers.splice(0).forEach((dispose) => dispose());
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
  vi.useRealTimers();
  document.body.innerHTML = '';
});

describe('realtime protocol boundaries', () => {
  it('validates declarations, scope endpoints, expiry, and display-only messages', () => {
    expect(realtimeMetadata(metadata)).toBe(true);
    expect(
      realtimeMetadata({
        ...metadata,
        endpoint: 'https://foreign.test/subscribe',
      }),
    ).toBe(false);
    expect(realtimeMetadata({ ...metadata, endpoint: 'http://[' })).toBe(false);
    expect(realtimeMetadata({ ...metadata, lease: '' })).toBe(false);
    expect(
      subscriptions([{ ...metadata.subscriptions[0], mode: 'unknown' }]),
    ).toBe(false);
    expect(
      subscriptions(
        Array.from({ length: 129 }, () => metadata.subscriptions[0]),
      ),
    ).toBe(false);
    expect(pushMessage(message)).toBe(true);
    expect(
      pushMessage({
        ...message,
        display: {
          type: 'target',
          to: 'answer',
          content: 'text',
          replace: false,
          html: false,
        },
      }),
    ).toBe(true);
    expect(
      pushMessage({
        ...message,
        display: { type: 'event', name: 'changed', detail: {} },
      }),
    ).toBe(false);
    expect(pushMessage({ ...message, id: 'bad id' })).toBe(false);
    expect(pushMessage({ ...message, detail: { constructor: 'unsafe' } })).toBe(
      false,
    );
    expect(
      realtimeGrant({
        driver: 'test',
        data: {},
        expires: Math.floor(Date.now() / 1000) + 1,
      }),
    ).toBe(true);
    expect(realtimeGrant({ driver: 'test', data: {}, expires: 1 })).toBe(false);
    for (const value of [
      '',
      '*',
      'has space',
      'has\nline',
      'a'.repeat(2049),
      1,
    ])
      expect(topic(value)).toBe(false);
  });
});

describe('shared connection lifecycle', () => {
  it('batches leases, shares a connection, filters events, deduplicates, and reference-counts disposal', async () => {
    const fetcher = vi.fn<typeof fetch>(async () =>
      authorization(['one', 'two']),
    );
    vi.stubGlobal('fetch', fetcher);
    let callbacks: TransportCallbacks | undefined;
    const close = vi.fn();
    const transport = vi.fn((_grant, events: TransportCallbacks) => {
      callbacks = events;
      return close;
    });
    const coordinator = new RealtimeCoordinator({
      transports: { test: transport },
    });
    const one = owner('one');
    const two = owner('two');
    const first = coordinator.join(one, metadata, 'csrf');
    const second = coordinator.join(two, metadata, 'csrf');
    disposers.push(
      () => first.dispose(),
      () => second.dispose(),
    );
    await vi.advanceTimersByTimeAsync(0);
    expect(fetcher).toHaveBeenCalledOnce();
    expect(fetcher.mock.calls[0]?.[1]?.body).toBe(
      JSON.stringify({ version: 1, leases: { one: 'lease', two: 'lease' } }),
    );
    expect(transport).toHaveBeenCalledOnce();
    callbacks?.status('connected');
    expect(one.invalidate).toHaveBeenCalledOnce();
    callbacks?.receive(message);
    callbacks?.receive(message);
    callbacks?.receive({ ...message, id: 'other', event: 'unmatched' });
    expect(one.receive).toHaveBeenCalledOnce();
    expect(two.receive).toHaveBeenCalledOnce();
    first.update({ ...metadata, lease: 'new-accepted-lease' });
    await vi.advanceTimersByTimeAsync(0);
    expect(transport).toHaveBeenCalledOnce();
    first.dispose();
    await vi.advanceTimersByTimeAsync(0);
    expect(close).toHaveBeenCalledOnce();
    second.dispose();
    expect(close).toHaveBeenCalledTimes(2);
  });

  it('discards obsolete grant responses and callbacks from replaced connections', async () => {
    let finish: ((response: Response) => void) | undefined;
    const held = new Promise<Response>((resolve) => {
      finish = resolve;
    });
    const fetcher = vi
      .fn()
      .mockResolvedValue(authorization(['one']))
      .mockReturnValueOnce(held);
    vi.stubGlobal('fetch', fetcher);
    const callbacks: TransportCallbacks[] = [];
    const transport = vi.fn((_grant, events: TransportCallbacks) => {
      callbacks.push(events);
      return vi.fn();
    });
    const connection = new RealtimeConnection(
      '/subscribe',
      'csrf',
      new Map([['test', transport]]),
    );
    disposers.push(() => connection.dispose());
    const one = owner('one');
    connection.update(one, metadata);
    await vi.advanceTimersByTimeAsync(0);
    connection.update(one, {
      ...metadata,
      subscriptions: [
        { topic: 'records/2', mode: 'refresh', event: null, private: true },
      ],
    });
    finish?.(authorization(['one']));
    await vi.advanceTimersByTimeAsync(0);
    expect(fetcher).toHaveBeenCalledTimes(2);
    expect(transport).toHaveBeenCalledOnce();
    connection.remove('missing');
    connection.update(one, metadata);
    await vi.advanceTimersByTimeAsync(0);
    connection.dispose();
    callbacks[0]?.receive(message);
    callbacks[0]?.status('connected');
    expect(one.receive).not.toHaveBeenCalled();
  });

  it('isolates rejected owners, malformed messages, and transport registration failures', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () =>
        authorization(['one'], {
          islands: {
            one: { error: 'Denied' },
            two: { lease: 'renewed', subscriptions: metadata.subscriptions },
          },
        }),
      ),
    );
    let callbacks: TransportCallbacks | undefined;
    const connection = new RealtimeConnection(
      '/subscribe',
      'csrf',
      new Map([
        [
          'test',
          (_grant, events) => {
            callbacks = events;
            return vi.fn();
          },
        ],
      ]),
    );
    disposers.push(() => connection.dispose());
    const one = owner('one');
    const two = owner('two');
    connection.update(one, metadata);
    connection.update(two, metadata);
    await vi.advanceTimersByTimeAsync(0);
    callbacks?.status('connected');
    expect(one.feedback.status).toBe('error');
    expect(two.feedback.status).toBe('connected');
    callbacks?.receive({ ...message, version: 2 });
    expect(two.feedback.status).toBe('error');
    expect(() =>
      new RealtimeCoordinator().registerTransport('Invalid', () => vi.fn()),
    ).toThrow();
  });

  it('renews credentials without normal requests and expires a connection when renewal fails', async () => {
    const fetcher = vi
      .fn()
      .mockResolvedValueOnce(authorization(['one']))
      .mockRejectedValue(new TypeError('offline'));
    vi.stubGlobal('fetch', fetcher);
    const close = vi.fn();
    const one = owner('one');
    const connection = new RealtimeConnection(
      '/subscribe',
      'csrf',
      new Map([['test', () => close]]),
    );
    disposers.push(() => connection.dispose());
    connection.update(one, metadata);
    await vi.advanceTimersByTimeAsync(240_000);
    expect(fetcher.mock.calls.length).toBeGreaterThan(1);
    await vi.advanceTimersByTimeAsync(60_000);
    expect(close).toHaveBeenCalled();
    expect(one.feedback.status).toBe('error');
  });

  it.each([
    {},
    { version: 1, islands: {}, grant: null },
    {
      version: 1,
      islands: {
        one: { lease: 'renewed', subscriptions: metadata.subscriptions },
      },
      grant: { driver: 'unknown', data: {}, expires: 9999999999 },
    },
  ])(
    'reports invalid or unavailable grants without affecting ordinary request state',
    async (body) => {
      vi.stubGlobal(
        'fetch',
        vi.fn(async () => new Response(JSON.stringify(body))),
      );
      const connection = new RealtimeConnection(
        '/subscribe',
        'csrf',
        new Map(),
      );
      disposers.push(() => connection.dispose());
      const one = owner('one');
      connection.update(one, metadata);
      await vi.advanceTimersByTimeAsync(0);
      expect(one.feedback.status).toBe('error');
    },
  );
});

describe('island invalidation and delivery', () => {
  function island() {
    const feedback = Alpine.reactive<RealtimeFeedback>({
      status: 'disabled',
      stale: false,
      error: null,
    });
    const bridge = {
      realtime: feedback,
      sync: vi.fn(async () => ({ ok: true as const })),
      receiveStream: vi.fn(),
    };
    const root = document.createElement('div');
    root.dataset.interactId = 'one';
    document.body.append(root);
    const coordinator = new RealtimeCoordinator();
    const eligible = vi.fn(() => true);
    // Only this class's declared bridge members are consumed by the fixture.
    const full: IslandBridge = {
      ...bridge,
      uploads: {},
      state: {},
      busy: false,
      pending: () => false,
      loading: () => false,
      errors: {},
      error: null,
      paused: false,
      exportDraft: () => '{}',
      dirty: false,
      call: async () => ({ ok: true }),
    };
    const client = new RealtimeIsland(root, full, coordinator, eligible);
    disposers.push(() => client.dispose());
    return { client, bridge, root, eligible, feedback };
  }

  it('coalesces bursts, defers blocked drafts, and preserves newer generations', async () => {
    const { client, bridge, eligible, feedback } = island();
    eligible.mockReturnValue(false);
    client.invalidate();
    client.invalidate();
    await vi.advanceTimersByTimeAsync(1000);
    expect(bridge.sync).not.toHaveBeenCalled();
    eligible.mockReturnValue(true);
    client.flush();
    await vi.advanceTimersByTimeAsync(100);
    expect(bridge.sync).toHaveBeenCalledOnce();
    const generation = client.begin();
    client.invalidate();
    client.accepted(true, generation);
    expect(feedback.stale).toBe(true);
    client.accepted(false, client.begin());
    expect(feedback.stale).toBe(true);
    client.accepted(true, client.begin());
    expect(feedback.stale).toBe(false);
  });

  it('keeps reactive eligibility dependencies when a scheduled refresh becomes blocked', async () => {
    const { client, bridge, eligible, feedback } = island();
    const activity = Alpine.reactive({ busy: false });
    eligible.mockImplementation(() => !activity.busy);
    const effect = Alpine.effect(() => client.flush());
    disposers.push(() => Alpine.release(effect));
    client.invalidate();
    await Promise.resolve();
    activity.busy = true;
    await vi.advanceTimersByTimeAsync(100);
    expect(bridge.sync).not.toHaveBeenCalled();
    activity.busy = false;
    await vi.advanceTimersByTimeAsync(100);
    expect(bridge.sync).toHaveBeenCalledOnce();
    client.accepted(true, client.begin());
    expect(feedback.stale).toBe(false);
  });

  it('routes notifications and provisional targets exactly once and releases disposed owners', () => {
    const { client, bridge, root, feedback } = island();
    const receive = vi.fn();
    root.addEventListener('interact:push', receive);
    const display = {
      type: 'target' as const,
      to: 'answer',
      content: 'text',
      replace: true,
      html: false,
    };
    client.receive({ ...message, display }, [
      { topic: 'records/1', mode: 'event', event: null, private: true },
      { topic: 'records/1', mode: 'stream', event: null, private: true },
    ]);
    expect(receive).toHaveBeenCalledOnce();
    expect(bridge.receiveStream).toHaveBeenCalledWith({
      ...display,
      version: 1,
      id: 'one',
    });
    client.update(undefined, 'csrf');
    expect(feedback.status).toBe('disabled');
    client.dispose();
    client.receive(message, metadata.subscriptions);
    client.update(metadata, 'csrf');
    expect(feedback.stale).toBe(false);
  });

  it('defers hidden documents and cancels a scheduled refresh on disposal', async () => {
    const { client, bridge } = island();
    vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('hidden');
    client.invalidate();
    await vi.advanceTimersByTimeAsync(1000);
    expect(bridge.sync).not.toHaveBeenCalled();
    vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('visible');
    document.dispatchEvent(new Event('visibilitychange'));
    client.dispose();
    await vi.advanceTimersByTimeAsync(1000);
    expect(bridge.sync).not.toHaveBeenCalled();
  });
});
