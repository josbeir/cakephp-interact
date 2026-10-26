import { afterEach, expect, it, vi } from 'vite-plus/test';
import {
  ActionEvents,
  actionListeners,
} from '../../../resources/ts/events/actions';
import { readMetadata } from '../../../resources/ts/transport/metadata';
import { mergeChanges } from '../../../resources/ts/state/paths';
import { state } from '../../../resources/ts/state/values';
import type { IslandClient } from '../../../resources/ts/client';
import type { Fetcher, State } from '../../../resources/ts/types';
import { destroyBindings, mountClient } from '../support/mount';

const clients: IslandClient[] = [];

function setup(
  options: {
    pending?: boolean;
    renderless?: boolean;
    beforeReply?: (request: State) => Promise<void>;
  } = {},
) {
  const root = document.createElement('div');
  const listeners = { 'article-saved': ['saved', 'refresh'] };
  Object.assign(root.dataset, {
    interactId: 'listener',
    interactSnapshot: 'signed',
    interactState: JSON.stringify(options.pending ? {} : { draft: '' }),
    interactWritable: JSON.stringify(
      options.pending ? {} : { draft: { type: 'string', nullable: false } },
    ),
    interactEndpoint: '/_interact/update',
    ...(options.pending
      ? { interactLoading: 'manual' }
      : { interactListeners: JSON.stringify(listeners) }),
  });
  root.innerHTML = '<input aria-label="Draft" x-interact:model="draft">';
  if (options.pending) root.innerHTML = 'Pending';
  document.body.append(root);
  let accepted: State = { draft: '' };
  const requests: State[] = [];
  const fetcher: Fetcher = async (_url, init) => {
    if (typeof init?.body !== 'string') throw new Error('Missing JSON body.');
    const request: unknown = JSON.parse(init.body);
    if (!state(request) || !state(request.updates))
      throw new Error('Invalid request.');
    requests.push(request);
    await options.beforeReply?.(request);
    accepted = mergeChanges(accepted, request.updates);
    const replacement = root.cloneNode(true);
    if (!(replacement instanceof HTMLElement)) throw new Error('Missing root.');
    delete replacement.dataset.interactLoading;
    replacement.dataset.interactListeners = JSON.stringify(listeners);
    replacement.dataset.interactState = JSON.stringify(accepted);
    replacement.dataset.interactWritable = JSON.stringify({
      draft: { type: 'string', nullable: false },
    });
    replacement.innerHTML =
      '<input aria-label="Draft" x-interact:model="draft">';
    return new Response(
      JSON.stringify({
        version: 1,
        id: 'listener',
        snapshot: 'next',
        html: options.renderless ? null : replacement.outerHTML,
        state: accepted,
        writable: { draft: { type: 'string', nullable: false } },
        errors: {},
        events: [],
      }),
      { headers: { 'Content-Type': 'application/json' } },
    );
  };
  const client = mountClient(root, fetcher, (from, to) => {
    for (const attribute of from.getAttributeNames())
      from.removeAttribute(attribute);
    for (const attribute of to.attributes)
      from.setAttribute(attribute.name, attribute.value);
    from.innerHTML = to.innerHTML;
  });
  clients.push(client);
  return { root, client, requests };
}

function emit(detail: unknown = { id: 1 }, target: EventTarget = window) {
  target.dispatchEvent(
    new CustomEvent('article-saved', { detail, bubbles: true }),
  );
}

afterEach(() => {
  for (const client of clients.splice(0)) {
    client.dispose();
    destroyBindings(client.root);
  }
  document.body.innerHTML = '';
  vi.restoreAllMocks();
});

it.each([
  null,
  [],
  'bad',
  { 'Bad event': ['saved'] },
  { saved: [] },
  { saved: ['saved', 'saved'] },
  { saved: [1] },
  { saved: [''] },
  { 'saved\n': ['saved'] },
])('rejects malformed listener metadata %j', (value) =>
  expect(actionListeners(value)).toBe(false),
);

it('rejects malformed root declarations before installing listeners', () => {
  const root = document.createElement('div');
  root.dataset.interactListeners = '{"saved":[]}';
  expect(() => readMetadata(root)).toThrow('Invalid action listener metadata.');
});

it('queues every declared handler and copies event data immediately while capturing drafts', async () => {
  let release: () => void = () => {};
  const held = new Promise<void>((resolve) => {
    release = resolve;
  });
  const { root, client, requests } = setup({ beforeReply: async () => held });
  const input = root.querySelector('input');
  if (!input) throw new Error('Missing draft.');
  input.value = 'unblurred draft';
  const detail = { id: 0, nested: { value: false }, note: null };
  emit(detail, root);
  detail.nested.value = true;
  expect(client.bridge.pending('saved')).toBe(true);
  expect(client.bridge.pending('refresh')).toBe(true);
  await vi.waitFor(() => expect(requests).toHaveLength(1));
  expect(requests[0]).toMatchObject({
    action: 'saved',
    event: 'article-saved',
    args: [{ id: 0, nested: { value: false }, note: null }],
    updates: { draft: 'unblurred draft' },
  });
  release();
  await vi.waitFor(() => expect(requests).toHaveLength(2));
  await vi.waitFor(() => expect(client.bridge.busy).toBe(false));
  expect(requests[1]).toMatchObject({
    action: 'refresh',
    event: 'article-saved',
    args: [{ nested: { value: false } }],
  });
  expect(client.bridge.state.draft).toBe('unblurred draft');
});

it('delivers to sibling and emitting islands while preserving native stop propagation', async () => {
  const first = setup();
  const second = setup();
  const stop = (event: Event) => event.stopPropagation();
  first.root.addEventListener('article-saved', stop);
  emit({}, first.root);
  expect(first.requests).toHaveLength(0);
  expect(second.requests).toHaveLength(0);
  first.root.removeEventListener('article-saved', stop);
  emit({}, first.root);
  await vi.waitFor(() => expect(first.requests).toHaveLength(2));
  await vi.waitFor(() => expect(second.requests).toHaveLength(2));
});

it.each([false, true])(
  'keeps a single registration after ordinary and renderless responses (%s)',
  async (renderless) => {
    const { client, requests } = setup({ renderless });
    if (!renderless) {
      await client.bridge.sync();
      await client.bridge.sync();
    }
    emit();
    await vi.waitFor(() => expect(client.bridge.pending()).toBe(false));
    emit();
    await vi.waitFor(() => expect(client.bridge.pending()).toBe(false));
    expect(
      requests.filter((request) => request.action === 'saved'),
    ).toHaveLength(2);
    expect(
      requests.filter((request) => request.action === 'refresh'),
    ).toHaveLength(2);
    client.dispose();
    const count = requests.length;
    emit();
    expect(requests).toHaveLength(count);
  },
);

it('ignores events before initialization and installs the initialized declarations once', async () => {
  const { client, requests } = setup({ pending: true });
  emit();
  expect(requests).toHaveLength(0);
  expect(await client.bridge.sync()).toEqual({ ok: true });
  emit();
  await vi.waitFor(() => expect(requests).toHaveLength(3));
  expect(requests[0]).toMatchObject({ initialize: true, action: null });
  expect(requests[1]).toMatchObject({
    action: 'saved',
    event: 'article-saved',
  });
});

it.each([1, 'bad', [], { nested: { constructor: true } }])(
  'reports malformed event detail without sending it (%j)',
  async (detail) => {
    const { client, requests } = setup();
    emit(detail);
    expect(requests).toHaveLength(0);
    expect(client.bridge.error?.code).toBe('client_error');
  },
);

it('accepts native events and null details as empty records', async () => {
  const { client, requests } = setup();
  window.dispatchEvent(new Event('article-saved'));
  await vi.waitFor(() => expect(client.bridge.pending()).toBe(false));
  emit(null);
  await vi.waitFor(() => expect(client.bridge.pending()).toBe(false));
  expect(requests).toHaveLength(4);
  expect(
    requests.every((request) => JSON.stringify(request.args) === '[{}]'),
  ).toBe(true);
});

it('does not translate streaming or realtime notification names into action events', () => {
  const { root, requests } = setup();
  for (const name of ['interact:push', 'interact:stream']) {
    root.dispatchEvent(
      new CustomEvent(name, {
        bubbles: true,
        detail: { event: 'article-saved', detail: { id: 1 } },
      }),
    );
  }
  expect(requests).toHaveLength(0);
});

it('reports rejected invocation promises and releases its listeners', async () => {
  const failed = vi.fn();
  const invoke = vi.fn(async () => {
    throw new Error('Failed invocation.');
  });
  const listener = new ActionEvents(
    { 'article-saved': ['saved'] },
    invoke,
    failed,
  );
  emit();
  await vi.waitFor(() => expect(failed).toHaveBeenCalledOnce());
  listener.dispose();
  emit();
  expect(invoke).toHaveBeenCalledOnce();
});

it('retains normal action failure feedback without automatically retrying event actions', async () => {
  const { client, requests } = setup({
    beforeReply: async () => {
      throw new Error('Network failure.');
    },
  });
  emit();
  await vi.waitFor(() =>
    expect(client.bridge.error?.code).toBe('network_error'),
  );
  expect(client.bridge.paused).toBe(true);
  expect(requests).toHaveLength(1);
  expect(client.bridge.pending()).toBe(false);
});
