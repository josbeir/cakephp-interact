import Alpine from '@alpinejs/csp';
import { afterEach, expect, it, vi } from 'vite-plus/test';
import { Relationships } from '../../resources/ts/composition/relationships';
import { relation } from '../../resources/ts/composition/protocol';
import type { IslandClient } from '../../resources/ts/client';
import type { State, Writable } from '../../resources/ts/types';
import { clone, record, state } from '../../resources/ts/state/values';
import { destroyBindings, mountClient } from './mount';
import { readMetadata } from '../../resources/ts/transport/metadata';

const clients: IslandClient[] = [];
const roots = new WeakMap<HTMLElement, IslandClient>();
type Server = (
  request: Record<string, unknown>,
  current: State,
) => Promise<State>;

function root(id: string, values: State, fields: Writable): HTMLElement {
  const element = document.createElement('div');
  Object.assign(element.dataset, {
    interactId: id,
    interactSnapshot: 'signed',
    interactState: JSON.stringify(values),
    interactWritable: JSON.stringify(fields),
    interactEndpoint: '/_interact/update',
  });
  return element;
}

function participant(
  element: HTMLElement,
  relationships: Relationships,
  handler?: Server,
) {
  const metadata = readMetadata(element);
  let accepted = clone(metadata.state);
  const requests: Record<string, unknown>[] = [];
  const client = mountClient(
    element,
    async (_url, options) => {
      if (typeof options?.body !== 'string')
        throw new Error('Expected request JSON.');
      const request: unknown = JSON.parse(options.body);
      if (!record(request) || !state(request.updates))
        throw new Error('Invalid request.');
      requests.push(request);
      accepted = { ...accepted, ...request.updates };
      const next = handler ? await handler(request, clone(accepted)) : accepted;
      accepted = clone(next);
      return new Response(
        JSON.stringify({
          version: 1,
          id: element.dataset.interactId,
          html: element.outerHTML,
          snapshot: 'next',
          state: next,
          writable: metadata.writable,
          errors: {},
          events: [],
        }),
        { headers: { 'Content-Type': 'application/json' } },
      );
    },
    () => {},
  );
  clients.push(client);
  roots.set(element, client);
  relationships.register(client);
  return { client, requests };
}

async function fixture(
  options: {
    timing?: string;
    delay?: number;
    parentServer?: Server;
    childServer?: Server;
    models?: unknown;
  } = {},
) {
  const relationships = new Relationships(Alpine, (element) =>
    roots.get(element),
  );
  const parentRoot = root(
    'parent',
    {
      data: { value: '', end: '', flag: false, selected: null, items: [] },
      unrelated: 'keep',
    },
    {
      data: { type: 'array', nullable: false },
      unrelated: { type: 'string', nullable: false },
    },
  );
  document.body.append(parentRoot);
  const parent = participant(parentRoot, relationships, options.parentServer);
  const childRoot = root(
    'child',
    { value: '', end: '', notes: '' },
    {
      value: { type: 'string', nullable: false },
      end: { type: 'string', nullable: false },
      notes: { type: 'string', nullable: false },
    },
  );
  childRoot.dataset.interactRelation = JSON.stringify({
    parentId: 'parent',
    models: options.models ?? {
      value: {
        path: 'data.value',
        timing: options.timing ?? 'defer',
        delay: options.delay ?? 250,
      },
      end: { path: 'data.end', timing: 'defer', delay: 250 },
    },
  });
  childRoot.innerHTML =
    '<input aria-label="Value" x-interact:model="value"><input aria-label="End" x-interact:model="end"><input aria-label="Notes" x-interact:model="notes">';
  parentRoot.append(childRoot);
  const child = participant(childRoot, relationships, options.childServer);
  await Promise.resolve();
  const input = childRoot.querySelector('input');
  if (!input) throw new Error('Missing child input.');
  return { parent, child, parentRoot, childRoot, input, relationships };
}

function edit(input: HTMLInputElement, value: string) {
  input.value = value;
  input.dispatchEvent(new Event('input', { bubbles: true }));
}

afterEach(() => {
  for (const client of clients.splice(0).reverse()) {
    client.dispose();
    destroyBindings(client.root);
  }
  document.body.innerHTML = '';
  vi.useRealTimers();
  vi.restoreAllMocks();
});

it('mirrors multiple local values in both directions without sending requests by default', async () => {
  const { parent, child, input } = await fixture();
  edit(input, 'chosen');
  child.client.bridge.state.end = 'second';
  await vi.waitFor(() =>
    expect(parent.client.bridge.state.data).toMatchObject({
      value: 'chosen',
      end: 'second',
    }),
  );
  parent.client.bridge.state.data = {
    value: 'reset',
    end: '',
    flag: false,
    selected: null,
    items: [],
  };
  await vi.waitFor(() => expect(child.client.bridge.state.value).toBe('reset'));
  expect(input.value).toBe('reset');
  expect(child.client.bridge.state.notes).toBe('');
  expect(parent.requests).toHaveLength(0);
  expect(child.requests).toHaveLength(0);
});

it('captures edits before blur or an input event when the parent action submits', async () => {
  const { parent, input } = await fixture();
  input.value = 'not yet dispatched';
  await parent.client.bridge.call('save');
  expect(parent.requests[0]).toMatchObject({
    action: 'save',
    updates: { data: { value: 'not yet dispatched' } },
  });
});

it('propagates PHP-normalized child values and retains unrelated drafts', async () => {
  const { parent, child, input } = await fixture({
    childServer: async (_request, current) => {
      if (typeof current.value !== 'string')
        throw new Error('Expected a string value.');
      return { ...current, value: current.value.trim().toUpperCase() };
    },
  });
  edit(input, '  selected  ');
  parent.client.bridge.state.unrelated = 'new notes';
  await child.client.bridge.call('normalize');
  await vi.waitFor(() =>
    expect(parent.client.bridge.state.data).toMatchObject({
      value: 'SELECTED',
    }),
  );
  expect(parent.client.bridge.state.unrelated).toBe('new notes');
  expect(parent.requests).toHaveLength(0);
});

it('retains newer child edits across a held parent response', async () => {
  let release: () => void = () => {};
  const held = new Promise<void>((resolve) => {
    release = resolve;
  });
  const { parent, child, input } = await fixture({
    parentServer: async (_request, current) => {
      await held;
      return current;
    },
  });
  edit(input, 'first');
  const saving = parent.client.bridge.call('save');
  await vi.waitFor(() => expect(parent.requests).toHaveLength(1));
  edit(input, 'newer');
  release();
  await saving;
  expect(parent.client.bridge.state.data).toMatchObject({ value: 'newer' });
  expect(child.client.bridge.state.value).toBe('newer');
});

it('protects a later parent reset even when it matches the child request’s initial value', async () => {
  let release: () => void = () => {};
  const held = new Promise<void>((resolve) => {
    release = resolve;
  });
  const { parent, child } = await fixture({
    childServer: async (request, current) => {
      if (request.action === 'preset') {
        await held;
        return { ...current, value: 'old preset' };
      }
      return current;
    },
  });
  const preset = child.client.bridge.call('preset');
  await vi.waitFor(() => expect(child.requests).toHaveLength(1));
  await parent.client.bridge.call('clear');
  release();
  await preset;
  await vi.waitFor(() => expect(child.client.bridge.busy).toBe(false));
  expect(child.client.bridge.state.value).toBe('');
  expect(parent.client.bridge.state.data).toMatchObject({ value: '' });
  expect(child.requests).toHaveLength(2);
});

it('does not let an unrelated parent refresh discard a child action’s result', async () => {
  let release: () => void = () => {};
  const held = new Promise<void>((resolve) => {
    release = resolve;
  });
  const { parent, child } = await fixture({
    childServer: async (_request, current) => {
      await held;
      return { ...current, value: 'accepted' };
    },
  });
  const selecting = child.client.bridge.call('choose');
  await vi.waitFor(() => expect(child.requests).toHaveLength(1));
  await parent.client.bridge.sync();
  release();
  await selecting;
  expect(parent.client.bridge.state.data).toMatchObject({ value: 'accepted' });
});

it.each(['change', 'live'])(
  'uses %s timing for the parent without echo requests',
  async (timing) => {
    const { parent, child, input } = await fixture({ timing, delay: 0 });
    edit(input, 'new');
    await vi.waitFor(() => expect(parent.requests).toHaveLength(1));
    await vi.waitFor(() => expect(child.client.bridge.busy).toBe(false));
    expect(parent.requests[0]).toMatchObject({
      action: null,
      updates: { data: { value: 'new' } },
    });
    expect(parent.requests).toHaveLength(1);
  },
);

it('debounces live model changes and cancels timers on disposal', async () => {
  vi.useFakeTimers();
  const { parent, child, input } = await fixture({
    timing: 'live',
    delay: 300,
  });
  edit(input, 'one');
  edit(input, 'two');
  await vi.advanceTimersByTimeAsync(299);
  expect(parent.requests).toHaveLength(0);
  await vi.advanceTimersByTimeAsync(1);
  expect(parent.requests).toHaveLength(1);
  edit(input, 'three');
  child.client.dispose();
  await vi.advanceTimersByTimeAsync(1000);
  expect(parent.requests).toHaveLength(1);
});

it('waits until focus leaves the child for blur timing', async () => {
  const { parent, childRoot, input } = await fixture({ timing: 'blur' });
  edit(input, 'new');
  expect(parent.requests).toHaveLength(0);
  input.dispatchEvent(
    new FocusEvent('focusout', { bubbles: true, relatedTarget: childRoot }),
  );
  expect(parent.requests).toHaveLength(0);
  input.dispatchEvent(new FocusEvent('focusout', { bubbles: true }));
  await vi.waitFor(() => expect(parent.requests).toHaveLength(1));
});

it('sends generated input tickets only to the preserved direct child after parent acceptance', async () => {
  const { parent, child, parentRoot } = await fixture();
  const incoming = parentRoot.cloneNode(false);
  if (!(incoming instanceof HTMLElement))
    throw new Error('Missing replacement.');
  incoming.innerHTML =
    '<div data-interact-preserve="child" data-interact-parent-inputs="signed-new"></div>';
  parent.client.composition?.accepted(incoming);
  await vi.waitFor(() => expect(child.requests).toHaveLength(1));
  expect(child.requests[0]).toMatchObject({
    parentInputs: 'signed-new',
    updates: { value: '', end: '' },
  });
});

it('keeps the newest parent ticket while an older child request is executing', async () => {
  let release: () => void = () => {};
  const held = new Promise<void>((resolve) => {
    release = resolve;
  });
  const { child } = await fixture({
    childServer: async (request, current) => {
      if (request.parentInputs === 'old') await held;
      return current;
    },
  });
  child.client.receiveParentInputs('old');
  await vi.waitFor(() => expect(child.requests).toHaveLength(1));
  child.client.receiveParentInputs('new');
  release();
  await vi.waitFor(() => expect(child.requests).toHaveLength(2));
  expect(child.requests[1]).toMatchObject({ parentInputs: 'new' });
});

it('ignores missing targets, nested markers, and disposed children', async () => {
  const { parent, child, parentRoot } = await fixture();
  const incoming = parentRoot.cloneNode(false);
  if (!(incoming instanceof HTMLElement))
    throw new Error('Missing replacement.');
  incoming.innerHTML =
    '<div data-interact-preserve="absent" data-interact-parent-inputs="missing"></div><div data-interact-id="nested"><div data-interact-preserve="child" data-interact-parent-inputs="nested"></div></div>';
  parent.client.composition?.accepted(incoming);
  child.client.dispose();
  child.client.receiveParentInputs('ignored');
  expect(child.requests).toHaveLength(0);
});

it.each([
  null,
  [],
  {},
  { parentId: '', models: {} },
  { parentId: 4, models: {} },
  { parentId: 'parent', models: { value: null } },
  {
    parentId: 'parent',
    models: { value: { path: 4, timing: 'defer', delay: 250 } },
  },
  {
    parentId: 'parent',
    models: { value: { path: 'data.value', timing: 'unknown', delay: 250 } },
  },
  {
    parentId: 'parent',
    models: { value: { path: 'data.value', timing: 'live', delay: -1 } },
  },
  {
    parentId: 'parent',
    models: { value: { path: 'data.value', timing: 'live', delay: 60001 } },
  },
])('rejects malformed relationship metadata: %j', (value) => {
  expect(relation(value)).toBe(false);
});

it('rejects unsafe paths and locked child fields through normal client feedback', async () => {
  expect(() =>
    relation({
      parentId: 'parent',
      models: {
        value: { path: 'data.__proto__', timing: 'defer', delay: 250 },
      },
    }),
  ).toThrow();
  const { child } = await fixture({
    models: { locked: { path: 'data.value', timing: 'defer', delay: 250 } },
  });
  expect(child.client.bridge.error?.code).toBe('client_error');
});

it('reports invalid parent ownership and bootstrap JSON without starting requests', async () => {
  const relationships = new Relationships(Alpine, () => undefined);
  const element = root('orphan', {}, {});
  element.dataset.interactRelation = '{';
  document.body.append(element);
  const orphan = participant(element, relationships);
  await Promise.resolve();
  expect(orphan.client.bridge.error?.code).toBe('client_error');
  element.dataset.interactRelation = JSON.stringify({
    parentId: 'missing',
    models: {},
  });
  relationships.register(orphan.client);
  await Promise.resolve();
  expect(orphan.client.bridge.error?.code).toBe('client_error');
});

it.each([false, true])(
  'retains newer drafts and parent tickets through manual initialization (ticket arriving in flight: %s)',
  async (inFlight) => {
    let release: () => void = () => {};
    const held = new Promise<void>((resolve) => {
      release = resolve;
    });
    const relationships = new Relationships(Alpine, (element) =>
      roots.get(element),
    );
    const parentRoot = root(
      'pending-parent',
      { data: { value: '' } },
      { data: { type: 'array', nullable: false } },
    );
    document.body.append(parentRoot);
    const parent = participant(parentRoot, relationships);
    const childRoot = root('pending-child', {}, {});
    childRoot.dataset.interactLoading = 'manual';
    childRoot.dataset.interactRelation = JSON.stringify({
      parentId: 'pending-parent',
      models: { value: { path: 'data.value', timing: 'defer', delay: 250 } },
    });
    parentRoot.append(childRoot);
    const requests: unknown[] = [];
    let current: State = { value: '' };
    const fields: Writable = { value: { type: 'string', nullable: false } };
    const child = mountClient(
      childRoot,
      async (_url, options) => {
        if (typeof options?.body !== 'string')
          throw new Error('Expected request.');
        const request: unknown = JSON.parse(options.body);
        if (!record(request) || !state(request.updates))
          throw new Error('Missing updates.');
        requests.push(request);
        if (request.initialize === true && inFlight) await held;
        current = { ...current, ...request.updates };
        const incoming = root('pending-child', current, fields);
        return new Response(
          JSON.stringify({
            version: 1,
            id: 'pending-child',
            html: incoming.outerHTML,
            snapshot: 'next',
            state: current,
            writable: fields,
            errors: {},
            events: [],
          }),
          { headers: { 'Content-Type': 'application/json' } },
        );
      },
      (from, to) => {
        from.removeAttribute('data-interact-loading');
        from.dataset.interactState = to.dataset.interactState;
        from.dataset.interactWritable = to.dataset.interactWritable;
      },
    );
    clients.push(child);
    roots.set(childRoot, child);
    relationships.register(child);
    await Promise.resolve();
    parent.client.bridge.state.data = { value: 'new draft' };
    parent.client.composition?.capture();
    child.receiveParentInputs('latest-signed-props');
    expect(requests).toHaveLength(0);
    const initializing = child.bridge.sync();
    if (inFlight) {
      await vi.waitFor(() => expect(requests).toHaveLength(1));
      child.receiveParentInputs('props-during-initialization');
      release();
    }
    await initializing;
    await vi.waitFor(() => expect(requests).toHaveLength(2));
    await vi.waitFor(() => expect(child.bridge.busy).toBe(false));
    expect(requests[0]).toMatchObject({
      initialize: true,
      updates: {},
      parentInputs: 'latest-signed-props',
    });
    expect(requests[1]).toMatchObject({ updates: { value: 'new draft' } });
    if (inFlight)
      expect(requests[1]).toMatchObject({
        parentInputs: 'props-during-initialization',
      });
    expect(parent.client.bridge.state.data).toEqual({ value: 'new draft' });
    expect(child.bridge.state.value).toBe('new draft');
  },
);

it('disconnects surviving child references when the parent is disposed', async () => {
  const { parent, child, input } = await fixture({ timing: 'live' });
  parent.client.dispose();
  edit(input, 'orphaned');
  expect(parent.client.bridge.state.data).toMatchObject({ value: '' });
  expect(child.requests).toHaveLength(0);
});

it('rejects a missing bound value and does not resume a failed parent automatically', async () => {
  const { parent, child, input } = await fixture({
    timing: 'change',
    parentServer: async () => {
      throw new Error('Offline');
    },
  });
  await parent.client.bridge.sync();
  expect(parent.client.bridge.paused).toBe(true);
  edit(input, 'keep editing');
  expect(parent.requests).toHaveLength(1);
  await vi.waitFor(() =>
    expect(parent.client.bridge.state.data).toMatchObject({
      value: 'keep editing',
    }),
  );
  delete child.client.bridge.state.value;
  await vi.waitFor(() =>
    expect(child.client.bridge.error?.code).toBe('client_error'),
  );
});
