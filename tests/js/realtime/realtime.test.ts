import Alpine from '@alpinejs/csp';
import { readMetadata } from '../../../resources/ts/transport/metadata';
import { IslandClient } from '../../../resources/ts/client';
import { registerNavigation } from '../../../resources/ts/navigation/registry';
import { record } from '../../../resources/ts/state/values';
import {
  afterEach,
  beforeEach,
  describe,
  expect,
  it,
  vi,
} from 'vite-plus/test';
import { RealtimeCoordinator } from '../../../resources/ts/realtime/coordinator';
import { RealtimeConnection } from '../../../resources/ts/realtime/connection';
import { RealtimeIsland } from '../../../resources/ts/realtime/island';
import {
  authorizationBody,
  authorizationFits,
  pushMessage,
  realtimeGrant,
  realtimeMetadata,
  subscriptions,
  topic,
} from '../../../resources/ts/realtime/protocol';
import type {
  PushMessage,
  RealtimeFeedback,
  RealtimeMetadata,
  TransportCallbacks,
} from '../../../resources/ts/realtime/types';
import type { IslandBridge } from '../../../resources/ts/types';

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

function requestBody(init: RequestInit | undefined): string {
  if (typeof init?.body !== 'string')
    throw new Error('Expected a JSON subscription request.');
  return init.body;
}

function requestLeases(init: RequestInit | undefined) {
  const payload: unknown = JSON.parse(requestBody(init));
  if (!record(payload) || !record(payload.leases))
    throw new Error('Missing subscription leases.');
  return payload.leases;
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
  it('measures the complete authorization envelope in UTF-8 bytes', () => {
    const overhead = authorizationBody(new Map([['one', '']])).length;
    const lease = 'a'.repeat(1_048_576 - overhead);
    expect(authorizationFits(new Map([['one', lease]]))).toBe(true);
    expect(authorizationFits(new Map([['one', `${lease}a`]]))).toBe(false);
    expect(authorizationFits(new Map([['one', 'é'.repeat(524_288)]]))).toBe(
      false,
    );
    expect(authorizationFits(new Map([['one', '"'.repeat(524_288)]]))).toBe(
      false,
    );
  });

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
  it('bounds authorization batches without interrupting full groups and reuses released capacity', async () => {
    const batches: string[][] = [];
    const fetcher = vi.fn<typeof fetch>(async (_input, init) => {
      const ids = Object.keys(requestLeases(init));
      batches.push(ids);
      return ids.length > 64
        ? new Response(null, { status: 400 })
        : authorization(ids);
    });
    vi.stubGlobal('fetch', fetcher);
    const streams: { callbacks: TransportCallbacks; close: () => void }[] = [];
    const transport = vi.fn((_grant, callbacks: TransportCallbacks) => {
      const close = vi.fn();
      streams.push({ callbacks, close });
      callbacks.status('connected');
      return close;
    });
    const coordinator = new RealtimeCoordinator({
      transports: { test: transport },
    });
    const members = Array.from({ length: 129 }, (_, id) =>
      owner(`island-${id}`),
    );
    const join = (member: ReturnType<typeof owner>) => {
      const membership = coordinator.join(member, metadata, 'csrf');
      disposers.push(() => membership.dispose());
      return membership;
    };
    const memberships = members.slice(0, 64).map(join);
    await vi.advanceTimersByTimeAsync(0);
    expect(batches.map((batch) => batch.length)).toEqual([64]);
    memberships.push(...members.slice(64).map(join));
    await vi.advanceTimersByTimeAsync(0);
    expect(batches.map((batch) => batch.length)).toEqual([64, 64, 1]);
    expect(streams[0]?.close).not.toHaveBeenCalled();
    expect(transport).toHaveBeenCalledTimes(3);
    expect(
      members.every((member) => member.feedback.status === 'connected'),
    ).toBe(true);
    for (const stream of streams) stream.callbacks.receive(message);
    for (const member of members) expect(member.receive).toHaveBeenCalledOnce();

    // A new island joins the final group without replacing the two full grants.
    const extra = owner('extra');
    const extraMembership = coordinator.join(extra, metadata, 'csrf');
    disposers.push(() => extraMembership.dispose());
    await vi.advanceTimersByTimeAsync(0);
    expect(batches.at(-1)).toEqual(['island-128', 'extra']);
    expect(streams[0]?.close).not.toHaveBeenCalled();
    expect(streams[1]?.close).not.toHaveBeenCalled();
    expect(streams[2]?.close).toHaveBeenCalledOnce();

    // Emptying one group leaves its neighbors connected and removes its registry entry.
    for (const membership of memberships.slice(0, 64)) membership.dispose();
    await vi.advanceTimersByTimeAsync(0);
    expect(streams[0]?.close).toHaveBeenCalledOnce();
    expect(streams[1]?.close).not.toHaveBeenCalled();
    expect(streams[3]?.close).not.toHaveBeenCalled();
    const replacement = owner('replacement');
    const replacementMembership = coordinator.join(
      replacement,
      metadata,
      'csrf',
    );
    disposers.push(() => replacementMembership.dispose());
    await vi.advanceTimersByTimeAsync(0);
    expect(batches.at(-1)).toEqual(['island-128', 'extra', 'replacement']);
    expect(streams[1]?.close).not.toHaveBeenCalled();
    streams.at(-1)?.callbacks.receive({ ...message, id: 'next' });
    expect(extra.receive).toHaveBeenCalledOnce();
    expect(replacement.receive).toHaveBeenCalledOnce();

    // Released slots in a remaining group are filled before another connection opens.
    memberships[64]?.dispose();
    const reused = owner('reused');
    const reusedMembership = coordinator.join(reused, metadata, 'csrf');
    disposers.push(() => reusedMembership.dispose());
    await vi.advanceTimersByTimeAsync(0);
    expect(batches.at(-1)).toHaveLength(64);
    expect(batches.at(-1)).toContain('reused');
    expect(batches.at(-1)).not.toContain('island-64');
  });

  it('partitions state-heavy islands and moves accepted leases that outgrow their group', async () => {
    const batches: Record<string, unknown>[] = [];
    const fetcher = vi.fn<typeof fetch>(async (_input, init) => {
      const leases = requestLeases(init);
      batches.push(leases);
      expect(
        new TextEncoder().encode(requestBody(init)).length,
      ).toBeLessThanOrEqual(1_048_576);
      return authorization(Object.keys(leases), {
        islands: Object.fromEntries(
          Object.entries(leases).map(([id, lease]) => [
            id,
            { lease, subscriptions: metadata.subscriptions },
          ]),
        ),
      });
    });
    vi.stubGlobal('fetch', fetcher);
    const streams: { callbacks: TransportCallbacks; close: () => void }[] = [];
    const coordinator = new RealtimeCoordinator({
      transports: {
        test: (_grant, callbacks) => {
          const close = vi.fn();
          streams.push({ callbacks, close });
          callbacks.status('connected');
          return close;
        },
      },
    });
    const members = ['one', 'two', 'three'].map(owner);
    const memberships = members.map((member) => {
      const membership = coordinator.join(
        member,
        { ...metadata, lease: 'a'.repeat(410_000) },
        'csrf',
      );
      disposers.push(() => membership.dispose());
      return membership;
    });
    await vi.advanceTimersByTimeAsync(0);
    expect(batches.map(Object.keys)).toEqual([['one', 'two'], ['three']]);
    memberships[0]?.update({ ...metadata, lease: 'b'.repeat(700_000) });
    await vi.advanceTimersByTimeAsync(0);
    expect(batches.slice(2).map(Object.keys)).toEqual([['two'], ['one']]);
    expect(streams[1]?.close).not.toHaveBeenCalled();
    expect(
      members.every((member) => member.feedback.status === 'connected'),
    ).toBe(true);
    for (const stream of streams) stream.callbacks.receive(message);
    for (const member of members) expect(member.receive).toHaveBeenCalledOnce();
    memberships[0]?.dispose();
    expect(streams.at(-1)?.close).toHaveBeenCalledOnce();
    expect(streams[1]?.close).not.toHaveBeenCalled();
  });

  it('rebalances groups when background renewal enlarges their proofs', async () => {
    const batches: string[][] = [];
    const lease = 'r'.repeat(600_000);
    const fetcher = vi.fn<typeof fetch>(async (_input, init) => {
      const ids = Object.keys(requestLeases(init));
      batches.push(ids);
      expect(
        new TextEncoder().encode(requestBody(init)).length,
      ).toBeLessThanOrEqual(1_048_576);
      return authorization(ids, {
        islands: Object.fromEntries(
          ids.map((id) => [
            id,
            { lease, subscriptions: metadata.subscriptions },
          ]),
        ),
      });
    });
    vi.stubGlobal('fetch', fetcher);
    const transport = vi.fn((_grant, callbacks: TransportCallbacks) => {
      callbacks.status('connected');
      return vi.fn();
    });
    const coordinator = new RealtimeCoordinator({
      transports: { test: transport },
    });
    const members = ['one', 'two'].map(owner);
    const memberships = members.map((member) => {
      const membership = coordinator.join(
        member,
        { ...metadata, lease: 'a'.repeat(200_000) },
        'csrf',
      );
      disposers.push(() => membership.dispose());
      return membership;
    });
    await vi.advanceTimersByTimeAsync(0);
    expect(batches).toEqual([['one', 'two'], ['two'], ['one']]);
    expect(transport).toHaveBeenCalledTimes(2);
    for (const membership of memberships)
      expect(membership.current().lease).toBe(lease);
    expect(
      members.every((member) => member.feedback.status === 'connected'),
    ).toBe(true);
  });

  it('isolates a proof that cannot fit by itself without retrying an oversized request', async () => {
    const fetcher = vi.fn<typeof fetch>(async () => authorization(['small']));
    vi.stubGlobal('fetch', fetcher);
    const coordinator = new RealtimeCoordinator({
      transports: {
        test: (_grant, callbacks) => {
          callbacks.status('connected');
          return vi.fn();
        },
      },
    });
    const large = owner('large');
    const small = owner('small');
    for (const member of [large, small]) {
      const membership = coordinator.join(
        member,
        {
          ...metadata,
          lease: member === large ? 'a'.repeat(1_048_576) : 'small',
        },
        'csrf',
      );
      disposers.push(() => membership.dispose());
    }
    await vi.advanceTimersByTimeAsync(10_000);
    expect(fetcher).toHaveBeenCalledOnce();
    expect(large.feedback).toMatchObject({
      status: 'error',
      error: 'Realtime authorization request is too large.',
    });
    expect(small.feedback.status).toBe('connected');
  });

  it('reauthorizes newer accepted state instead of overwriting it with an in-flight renewal', async () => {
    let finish: ((response: Response) => void) | undefined;
    const fetcher = vi
      .fn<typeof fetch>(async () => authorization(['one']))
      .mockImplementationOnce(async () => authorization(['one']))
      .mockImplementationOnce(
        () =>
          new Promise<Response>((resolve) => {
            finish = resolve;
          }),
      )
      .mockImplementationOnce(async () =>
        authorization(['one'], {
          islands: {
            one: {
              lease: 'accepted-renewal',
              subscriptions: metadata.subscriptions,
            },
          },
        }),
      );
    vi.stubGlobal('fetch', fetcher);
    const one = owner('one');
    const connection = new RealtimeConnection(
      '/subscribe',
      'csrf',
      new Map([['test', () => vi.fn()]]),
    );
    disposers.push(() => connection.dispose());
    connection.update(one, metadata);
    await vi.advanceTimersByTimeAsync(240_000);
    expect(fetcher).toHaveBeenCalledTimes(2);
    connection.update(one, { ...metadata, lease: 'accepted-action' });
    finish?.(
      authorization(['one'], {
        islands: { one: { lease: 'obsolete-renewal', subscriptions: [] } },
        grant: null,
      }),
    );
    await vi.advanceTimersByTimeAsync(0);
    expect(fetcher).toHaveBeenCalledTimes(3);
    expect(requestLeases(fetcher.mock.calls[2]?.[1])).toEqual({
      one: 'accepted-action',
    });
    await vi.advanceTimersByTimeAsync(240_000);
    expect(requestLeases(fetcher.mock.calls[3]?.[1])).toEqual({
      one: 'accepted-renewal',
    });
  });

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
    return { client, bridge, root, eligible, feedback, coordinator };
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

  it('renews accepted membership, changes authorization scope, and releases the final subscription', async () => {
    const { client, feedback, coordinator } = island();
    const close = vi.fn();
    coordinator.registerTransport('test', () => close);
    const fetcher = vi.fn<typeof fetch>(async () => authorization(['one']));
    vi.stubGlobal('fetch', fetcher);
    client.update(metadata, 'csrf');
    await vi.advanceTimersByTimeAsync(0);
    client.update({ ...metadata, lease: 'new-accepted-lease' }, 'csrf');
    await vi.advanceTimersByTimeAsync(240000);
    expect(fetcher.mock.calls[1]?.[1]?.body).toBe(
      JSON.stringify({ version: 1, leases: { one: 'new-accepted-lease' } }),
    );
    client.update(metadata, 'other-csrf');
    await vi.advanceTimersByTimeAsync(0);
    expect(fetcher.mock.calls[2]?.[1]?.headers).toMatchObject({
      'X-CSRF-Token': 'other-csrf',
    });
    client.update(undefined, 'other-csrf');
    expect(feedback.status).toBe('disabled');
    expect(feedback.stale).toBe(false);
    expect(close).toHaveBeenCalledTimes(3);
  });

  it('uses refresh delivery without requiring a display payload', () => {
    const { client, bridge, feedback } = island();
    client.receive(message, metadata.subscriptions);
    expect(feedback.stale).toBe(true);
    client.receive(message, [
      { topic: message.topic, event: null, mode: 'stream', private: true },
    ]);
    expect(bridge.receiveStream).not.toHaveBeenCalled();
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

describe('authorization recovery and delivery boundaries', () => {
  async function active(members: ReturnType<typeof owner>[]) {
    const fetcher = vi.fn<typeof fetch>(async () =>
      authorization(members.map((member) => member.id)),
    );
    vi.stubGlobal('fetch', fetcher);
    const slot: { current?: TransportCallbacks } = {};
    const close = vi.fn();
    const connection = new RealtimeConnection(
      '/subscribe',
      'csrf',
      new Map([
        [
          'test',
          (_grant: unknown, events: TransportCallbacks) => {
            slot.current = events;
            return close;
          },
        ],
      ]),
    );
    disposers.push(() => connection.dispose());
    for (const member of members) connection.update(member, metadata);
    await vi.advanceTimersByTimeAsync(0);
    const callbacks = slot.current;
    if (!callbacks) throw new Error('Missing active transport.');
    callbacks.status('connected');
    return { fetcher, close, callbacks, connection };
  }

  it('closes the final member and cancels renewal without accepting late delivery', async () => {
    const one = owner('one');
    const { connection, callbacks, close, fetcher } = await active([one]);
    connection.remove('one');
    await vi.advanceTimersByTimeAsync(0);
    callbacks.receive(message);
    callbacks.status('connected');
    await vi.advanceTimersByTimeAsync(600000);
    expect(close).toHaveBeenCalledOnce();
    expect(fetcher).toHaveBeenCalledOnce();
    expect(one.receive).not.toHaveBeenCalled();
    expect(one.invalidate).toHaveBeenCalledOnce();
  });

  it('disables subscriptions removed by renewed authorization and closes the old grant', async () => {
    const one = owner('one');
    const { connection, close, fetcher } = await active([one]);
    one.feedback.stale = true;
    fetcher.mockImplementation(async () =>
      authorization(['one'], {
        islands: { one: { lease: 'unsubscribed', subscriptions: [] } },
        grant: null,
      }),
    );
    await vi.advanceTimersByTimeAsync(240000);
    expect(one.feedback).toEqual({
      status: 'disabled',
      error: null,
      stale: false,
    });
    expect(close).toHaveBeenCalledOnce();
    await vi.advanceTimersByTimeAsync(600000);
    expect(fetcher).toHaveBeenCalledTimes(2);
    connection.remove('one');
  });

  it('bounds duplicate suppression and isolates a recipient display failure', async () => {
    const one = owner('one');
    const two = owner('two');
    one.receive.mockImplementation(() => {
      throw new Error('Widget failed.');
    });
    const { callbacks } = await active([one, two]);
    for (let id = 0; id < 513; id++)
      callbacks.receive({ ...message, id: `event-${id}` });
    callbacks.receive({ ...message, id: 'event-512' });
    expect(two.receive).toHaveBeenCalledTimes(513);
    callbacks.receive({ ...message, id: 'event-0' });
    expect(two.receive).toHaveBeenCalledTimes(514);
    expect(one.feedback.error).toBe('Realtime display update failed.');
    expect(two.feedback.error).toBeNull();
    callbacks.status('reconnecting', 'Gateway offline');
    expect(two.feedback).toMatchObject({
      status: 'reconnecting',
      error: 'Gateway offline',
    });
  });

  it.each([
    new Response('', { status: 403 }),
    authorization(['one'], { grant: { driver: 'test', data: {}, expires: 1 } }),
  ])(
    'rejects HTTP or expired-grant authorization failures before connecting',
    async (response) => {
      const fetcher = vi.fn(async () => response);
      vi.stubGlobal('fetch', fetcher);
      const transport = vi.fn(() => vi.fn());
      const connection = new RealtimeConnection(
        '/subscribe',
        'csrf',
        new Map([['test', transport]]),
      );
      disposers.push(() => connection.dispose());
      const one = owner('one');
      connection.update(one, metadata);
      await vi.advanceTimersByTimeAsync(0);
      expect(transport).not.toHaveBeenCalled();
      expect(one.feedback).toMatchObject({
        status: 'error',
        error: 'Realtime authorization failed.',
      });
    },
  );
});

describe('bootstrap and client lifecycle integration', () => {
  function root() {
    const element = document.createElement('section');
    Object.assign(element.dataset, {
      interactId: 'one',
      interactSnapshot: 'snapshot',
      interactEndpoint: '/update',
      interactState: '{}',
      interactWritable: '{}',
    });
    document.body.append(element);
    return element;
  }

  it('retains background-renewed leases across compatible layout visits and CSRF changes', async () => {
    const element = root();
    const subscriptions = metadata.subscriptions.map((subscription) => ({
      ...subscription,
      mode: 'event' as const,
    }));
    element.dataset.interactRealtime = JSON.stringify({
      ...metadata,
      subscriptions,
    });
    let proof = metadata.lease;
    const fetcher = vi.fn<typeof fetch>(async (_input, init) => {
      expect(requestLeases(init)).toEqual({ one: proof });
      proof = `renewed-${fetcher.mock.calls.length}`;
      return authorization(['one'], {
        islands: { one: { lease: proof, subscriptions } },
      });
    });
    vi.stubGlobal('fetch', fetcher);
    const transport = vi.fn((_grant, callbacks: TransportCallbacks) => {
      callbacks.status('connected');
      return vi.fn();
    });
    const coordinator = new RealtimeCoordinator({
      transports: { test: transport },
    });
    const client = new IslandClient(element, () => {}, Alpine.reactive);
    disposers.push(() => client.dispose());
    client.attachRealtime(coordinator);
    await vi.advanceTimersByTimeAsync(300_001);
    expect(fetcher).toHaveBeenCalledTimes(2);
    client.refreshContext(element);
    await vi.advanceTimersByTimeAsync(180_000);
    expect(fetcher).toHaveBeenCalledTimes(3);
    const incoming = document.createElement('section');
    incoming.dataset.interactCsrf = 'new-csrf';
    client.refreshContext(incoming);
    await vi.advanceTimersByTimeAsync(0);
    expect(fetcher).toHaveBeenCalledTimes(4);
    expect(fetcher.mock.calls[3]?.[1]?.headers).toMatchObject({
      'X-CSRF-Token': 'new-csrf',
    });
    expect(client.bridge.realtime.status).toBe('connected');
  });

  it('reads valid subscription metadata and rejects malformed or pending subscription owners', () => {
    const element = root();
    element.dataset.interactRealtime = JSON.stringify(metadata);
    expect(readMetadata(element).realtime).toEqual(metadata);
    element.dataset.interactRealtime = '{}';
    expect(() => readMetadata(element)).toThrow('Invalid realtime metadata');
    element.dataset.interactRealtime = JSON.stringify(metadata);
    element.dataset.interactLoading = 'manual';
    expect(() => readMetadata(element)).toThrow(
      'Pending islands cannot subscribe',
    );
  });

  it('defers client refresh during navigation, then reconciles or removes accepted subscription metadata', async () => {
    const element = root();
    element.dataset.interactRealtime = JSON.stringify(metadata);
    const grantFetcher = vi.fn<typeof fetch>(async () =>
      authorization(['one']),
    );
    vi.stubGlobal('fetch', grantFetcher);
    const slot: { current?: TransportCallbacks } = {};
    const close = vi.fn();
    const coordinator = new RealtimeCoordinator({
      transports: {
        test: (_grant, callbacks) => {
          slot.current = callbacks;
          return close;
        },
      },
    });
    const updateFetcher = vi.fn(
      async () =>
        new Response(
          JSON.stringify({
            version: 1,
            id: 'one',
            snapshot: 'accepted',
            html: element.outerHTML,
            state: {},
            writable: {},
            errors: {},
            events: [],
            realtime: metadata,
          }),
          { headers: { 'Content-Type': 'application/json' } },
        ),
    );
    const client = new IslandClient(
      element,
      () => {},
      Alpine.reactive,
      updateFetcher,
    );
    disposers.push(() => client.dispose());
    const store = Alpine.reactive({ pending: true });
    disposers.push(
      registerNavigation(window, {
        activity: { store },
        epoch: () => 0,
        visit: async () => 'enhanced',
        invalidate: () => {},
        query: () => {},
      }),
    );
    client.attachRealtime(coordinator);
    const effect = Alpine.effect(() => client.flushRealtime());
    disposers.push(() => Alpine.release(effect));
    await vi.advanceTimersByTimeAsync(0);
    slot.current?.status('connected');
    await vi.advanceTimersByTimeAsync(1000);
    expect(client.bridge.realtime.stale).toBe(true);
    expect(updateFetcher).not.toHaveBeenCalled();
    store.pending = false;
    await vi.advanceTimersByTimeAsync(100);
    expect(updateFetcher).toHaveBeenCalledOnce();
    expect(client.bridge.realtime.stale).toBe(false);
    updateFetcher.mockImplementation(
      async () =>
        new Response(
          JSON.stringify({
            version: 1,
            id: 'one',
            snapshot: 'unsubscribed',
            html: element.outerHTML,
            state: {},
            writable: {},
            errors: {},
            events: [],
          }),
          { headers: { 'Content-Type': 'application/json' } },
        ),
    );
    await client.bridge.sync();
    expect(client.bridge.realtime.status).toBe('disabled');
    expect(close).toHaveBeenCalledOnce();
  });
});

it('aborts a pending grant request on disposal without scheduling authorization retries', async () => {
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
  const transport = vi.fn(() => vi.fn());
  const connection = new RealtimeConnection(
    '/subscribe',
    'csrf',
    new Map([['test', transport]]),
  );
  disposers.push(() => connection.dispose());
  connection.update(owner('one'), metadata);
  await vi.advanceTimersByTimeAsync(0);
  connection.dispose();
  await vi.advanceTimersByTimeAsync(300000);
  expect(fetcher).toHaveBeenCalledOnce();
  expect(fetcher.mock.calls[0]?.[1]?.signal?.aborted).toBe(true);
  expect(transport).not.toHaveBeenCalled();
  expect(vi.getTimerCount()).toBe(0);
});
