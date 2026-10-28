import Alpine from '@alpinejs/csp';
import { afterEach, expect, it, vi } from 'vite-plus/test';
import { Relationships } from '../../../resources/ts/composition/relationships';
import { relation } from '../../../resources/ts/composition/protocol';
import type { IslandClient } from '../../../resources/ts/client';
import type { State, Writable } from '../../../resources/ts/types';
import { clone, record, state } from '../../../resources/ts/state/values';
import { mergeChanges } from '../../../resources/ts/state/paths';
import { destroyBindings, mountClient } from '../support/mount';
import { readMetadata } from '../../../resources/ts/transport/metadata';

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
  content?: string,
  renderless = false,
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
      accepted = mergeChanges(accepted, request.updates);
      const next = handler ? await handler(request, clone(accepted)) : accepted;
      accepted = clone(next);
      const replacement = element.cloneNode(false);
      if (!(replacement instanceof HTMLElement))
        throw new Error('Missing replacement root.');
      replacement.innerHTML = content ?? element.innerHTML;
      return new Response(
        JSON.stringify({
          version: 1,
          id: element.dataset.interactId,
          html: renderless ? null : replacement.outerHTML,
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
    parentContent?: string;
    parentRenderless?: boolean;
    childRenderless?: boolean;
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
  const parent = participant(
    parentRoot,
    relationships,
    options.parentServer,
    options.parentContent,
    options.parentRenderless,
  );
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
  const child = participant(
    childRoot,
    relationships,
    options.childServer,
    undefined,
    options.childRenderless,
  );
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

it.each([false, true])(
  'avoids state copies and child discovery for an unrelated island (other bindings: %s)',
  async (otherBindings) => {
    const relationships = otherBindings
      ? (await fixture()).relationships
      : new Relationships(Alpine, (element) => roots.get(element));
    const element = root(
      'standalone',
      { value: 'current' },
      { value: { type: 'string', nullable: false } },
    );
    document.body.append(element);
    const { client } = participant(element, relationships);
    await Promise.resolve();
    const incoming = { value: 'accepted' };
    const parse = vi.spyOn(JSON, 'parse');
    const serialize = vi.spyOn(JSON, 'stringify');
    const discover = vi.spyOn(element, 'querySelectorAll');
    client.composition?.begin(null, { value: 'sent' });
    const changes = client.composition?.preserve(incoming);
    client.composition?.accepted(element);
    expect(parse).not.toHaveBeenCalled();
    expect(serialize).not.toHaveBeenCalled();
    expect(discover).not.toHaveBeenCalled();
    expect(changes).toEqual({});
    expect(incoming).toEqual({ value: 'accepted' });
  },
);

it('preserves a newly connected child action while an unbound parent request is in flight', async () => {
  let release = () => {};
  const held = new Promise<void>((resolve) => {
    release = resolve;
  });
  const { parent, child, parentRoot, childRoot, relationships } = await fixture(
    {
      parentServer: async (_request, current) => {
        await held;
        return current;
      },
    },
  );
  child.client.dispose();
  destroyBindings(childRoot);
  childRoot.remove();
  const saving = parent.client.bridge.call('save');
  await vi.waitFor(() => expect(parent.requests).toHaveLength(1));
  const element = root(
    'late-child',
    { value: '' },
    { value: { type: 'string', nullable: false } },
  );
  element.dataset.interactRelation = JSON.stringify({
    parentId: 'parent',
    models: { value: { path: 'data.value', timing: 'defer', delay: 250 } },
  });
  parentRoot.append(element);
  const late = participant(element, relationships, async () => ({
    value: 'newly accepted',
  }));
  await Promise.resolve();
  expect(await late.client.bridge.call('choose')).toEqual({ ok: true });
  release();
  expect(await saving).toEqual({ ok: true });
  expect(parent.client.bridge.state.data).toMatchObject({
    value: 'newly accepted',
  });
  expect(late.client.bridge.state.value).toBe('newly accepted');
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

it('checks the exact writable owner of scalar and nested model paths', async () => {
  const { parent, child } = await fixture();
  expect(child.client.canModel('value')).toBe(true);
  expect(parent.client.canModel('data.value')).toBe(true);
  expect(child.client.canModel('values')).toBe(false);
  expect(parent.client.canModel('database.value')).toBe(false);
  expect(child.client.canModel('')).toBe(false);
});

it.each(['page', 'limit'])(
  'mirrors a child model bound to the declared pagination field paging.%s',
  async (field) => {
    const relationships = new Relationships(Alpine, (element) =>
      roots.get(element),
    );
    const path = `paging.${field}`;
    const parentRoot = root(
      'parent',
      { paging: { page: 1, sort: '', direction: 'asc', limit: 20 } },
      { [path]: { type: 'int', nullable: false } },
    );
    document.body.append(parentRoot);
    const parent = participant(
      parentRoot,
      relationships,
      undefined,
      undefined,
      true,
    );
    const childRoot = root(
      'child',
      { value: field === 'page' ? 1 : 20 },
      { value: { type: 'int', nullable: false } },
    );
    childRoot.dataset.interactRelation = JSON.stringify({
      parentId: 'parent',
      models: { value: { path, timing: 'defer', delay: 250 } },
    });
    childRoot.innerHTML = '<input type="number" x-interact:model="value">';
    parentRoot.append(childRoot);
    const child = participant(childRoot, relationships);
    const failed = vi.spyOn(child.client, 'compositionFailed');
    await Promise.resolve();
    const input = childRoot.querySelector('input');
    if (!input) throw new Error('Missing child input.');

    edit(input, '2');
    await vi.waitFor(() =>
      expect(parent.client.bridge.state.paging).toMatchObject({ [field]: 2 }),
    );
    parent.client.bridge.state.paging = {
      page: 1,
      sort: '',
      direction: 'asc',
      limit: 20,
      [field]: 3,
    };
    await vi.waitFor(() => expect(child.client.bridge.state.value).toBe(3));
    expect(input.value).toBe('3');
    expect(parent.requests).toHaveLength(0);
    expect(child.requests).toHaveLength(0);
    expect(failed).not.toHaveBeenCalled();

    await parent.client.bridge.sync();
    expect(parent.requests[0]).toMatchObject({ updates: { [path]: 3 } });
    expect(parent.client.bridge.state.paging).toMatchObject({ [field]: 3 });
    expect(child.client.bridge.state.value).toBe(3);
  },
);

it('requires explicit pagination leaf capabilities without granting sibling or root access', () => {
  const element = root(
    'parent',
    { paging: { page: 1, sort: '', direction: 'asc' } },
    { 'paging.page': { type: 'int', nullable: false } },
  );
  document.body.append(element);
  const relationships = new Relationships(Alpine, (entry) => roots.get(entry));
  const { client } = participant(element, relationships);

  expect(client.canModel('paging.page')).toBe(true);
  expect(client.canModel('paging')).toBe(false);
  expect(client.canModel('paging.limit')).toBe(false);
  expect(client.canModel('paging.sort')).toBe(false);
  expect(client.canModel('paging.unknown')).toBe(false);
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
    childRenderless: true,
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

it('keeps a later accepted child action when an older parent response arrives', async () => {
  let release: () => void = () => {};
  const held = new Promise<void>((resolve) => {
    release = resolve;
  });
  const { parent, child } = await fixture({
    parentServer: async (_request, current) => {
      await held;
      return { ...current, data: { value: 'old parent value', end: '' } };
    },
    childServer: async (_request, current) => ({
      ...current,
      value: 'accepted child value',
    }),
  });
  const saving = parent.client.bridge.call('save');
  await vi.waitFor(() => expect(parent.requests).toHaveLength(1));
  await child.client.bridge.call('choose');
  release();
  await saving;
  expect(parent.client.bridge.state.data).toMatchObject({
    value: 'accepted child value',
  });
  expect(child.client.bridge.state.value).toBe('accepted child value');
  expect(
    child.requests.filter((request) => request.action === 'choose'),
  ).toHaveLength(1);
});

it.each([false, true])(
  'keeps epoch-protected child values and independent parent map changes (renderless: %s)',
  async (renderless) => {
    let release = () => {};
    const held = new Promise<void>((resolve) => {
      release = resolve;
    });
    const { parent, child } = await fixture({
      parentRenderless: renderless,
      childRenderless: renderless,
      parentServer: async (_request, current) => {
        await held;
        return mergeChanges(current, {
          'data.value': 'stale',
          'data.flag': true,
          'data.server': 'accepted',
        });
      },
      childServer: async (_request, current) => ({ ...current, end: 'new' }),
    });
    const saving = parent.client.bridge.call('save');
    await vi.waitFor(() => expect(parent.requests).toHaveLength(1));
    expect(await child.client.bridge.call('choose')).toEqual({ ok: true });
    expect(parent.client.bridge.state.data).toMatchObject({
      value: '',
      end: 'new',
    });
    release();
    expect(await saving).toEqual({ ok: true });
    expect(parent.client.bridge.state.data).toEqual({
      value: '',
      end: 'new',
      flag: true,
      selected: null,
      items: [],
      server: 'accepted',
    });
    expect(child.client.bridge.state.value).toBe('');
    expect(child.client.bridge.state.end).toBe('new');
  },
);

it('reports a removed parent model path instead of copying undefined into a late child response', async () => {
  let release: () => void = () => {};
  const held = new Promise<void>((resolve) => {
    release = resolve;
  });
  const { parent, child } = await fixture({
    parentRenderless: true,
    parentServer: async (_request, current) => ({
      ...current,
      data: { end: '' },
    }),
    childServer: async (_request, current) => {
      await held;
      return { ...current, value: 'late selection' };
    },
  });
  const choosing = child.client.bridge.call('choose');
  await vi.waitFor(() => expect(child.requests).toHaveLength(1));
  const removed = await parent.client.bridge.call('removePath');
  expect(removed).toMatchObject({ ok: false, error: { code: 'client_error' } });
  release();
  expect(await choosing).toMatchObject({
    ok: false,
    error: { code: 'client_error' },
  });
  expect(parent.client.bridge.state.data).toEqual({ end: '' });
  expect(child.client.bridge.state.value).toBe('late selection');
  expect(child.requests).toHaveLength(1);
});

it('omits an unavailable child model from preservation and recovers its native control value', async () => {
  let release: () => void = () => {};
  const held = new Promise<void>((resolve) => {
    release = resolve;
  });
  const { parent, child } = await fixture({
    parentServer: async (_request, current) => {
      await held;
      return current;
    },
    childServer: async (_request, current) => ({
      ...current,
      value: 'accepted selection',
    }),
  });
  const saving = parent.client.bridge.call('save');
  await vi.waitFor(() => expect(parent.requests).toHaveLength(1));
  await child.client.bridge.call('choose');
  delete child.client.bridge.state.value;
  await vi.waitFor(() =>
    expect(child.client.bridge.error?.code).toBe('client_error'),
  );
  expect(
    parent.client.composition?.preserve({ data: { value: '', end: '' } }),
  ).toEqual({});
  release();
  expect(await saving).toEqual({ ok: true });
  expect(parent.client.bridge.state.data).toMatchObject({
    value: 'accepted selection',
  });
  expect(child.client.bridge.state.value).toBe('accepted selection');
  expect(child.client.bridge.paused).toBe(true);
  expect(child.requests).toHaveLength(1);
});

it('preserves nullable model values independently of zero', async () => {
  const relationships = new Relationships(Alpine, (element) =>
    roots.get(element),
  );
  const parentRoot = root(
    'parent',
    { data: { value: null } },
    { data: { type: 'array', nullable: false } },
  );
  const childRoot = root(
    'child',
    { value: null },
    { value: { type: 'int', nullable: true } },
  );
  childRoot.dataset.interactRelation = JSON.stringify({
    parentId: 'parent',
    models: { value: { path: 'data.value', timing: 'defer', delay: 250 } },
  });
  document.body.append(parentRoot);
  const parent = participant(parentRoot, relationships);
  parentRoot.append(childRoot);
  const child = participant(childRoot, relationships);
  await Promise.resolve();
  child.client.bridge.state.value = 0;
  await vi.waitFor(() =>
    expect(parent.client.bridge.state.data).toEqual({ value: 0 }),
  );
  parent.client.bridge.state.data = { value: null };
  await vi.waitFor(() => expect(child.client.bridge.state.value).toBeNull());
  expect(parent.requests).toHaveLength(0);
  expect(child.requests).toHaveLength(0);
});

it('isolates sibling tickets while keeping their shared parent draft consistent', async () => {
  const { parent, child, parentRoot, input, relationships } = await fixture();
  const siblingRoot = root(
    'sibling',
    { value: '' },
    { value: { type: 'string', nullable: false } },
  );
  siblingRoot.dataset.interactRelation = JSON.stringify({
    parentId: 'parent',
    models: { value: { path: 'data.value', timing: 'defer', delay: 250 } },
  });
  parentRoot.append(siblingRoot);
  const sibling = participant(siblingRoot, relationships);
  await Promise.resolve();
  edit(input, 'shared selection');
  await vi.waitFor(() =>
    expect(sibling.client.bridge.state.value).toBe('shared selection'),
  );
  child.client.receiveParentInputs('first-child-only');
  await vi.waitFor(() => expect(child.requests).toHaveLength(1));
  await vi.waitFor(() => expect(child.client.bridge.busy).toBe(false));
  expect(child.requests[0]).toMatchObject({
    parentInputs: 'first-child-only',
    updates: { value: 'shared selection' },
  });
  expect(sibling.requests).toHaveLength(0);
  expect(parent.requests).toHaveLength(0);
});

it('protects a later parent reset even when it matches the child request’s initial value', async () => {
  let release: () => void = () => {};
  const held = new Promise<void>((resolve) => {
    release = resolve;
  });
  const { parent, child } = await fixture({
    parentRenderless: true,
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

it('clears live child-model timers captured by a parent action', async () => {
  vi.useFakeTimers();
  const { parent, input } = await fixture({
    timing: 'live',
    delay: 300,
    parentRenderless: true,
  });
  edit(input, 'captured');
  await Promise.resolve();
  await parent.client.bridge.call('save');
  expect(parent.requests[0]).toMatchObject({
    action: 'save',
    updates: { data: { value: 'captured' } },
  });
  await vi.advanceTimersByTimeAsync(1000);
  expect(parent.requests.map((request) => request.action)).toEqual(['save']);
});

it('retains live child-model timers for edits made after a parent request starts', async () => {
  vi.useFakeTimers();
  let release: () => void = () => {};
  const held = new Promise<void>((resolve) => {
    release = resolve;
  });
  const { parent, child, input } = await fixture({
    timing: 'live',
    delay: 300,
    parentRenderless: true,
    childRenderless: true,
    parentServer: async (request, current) => {
      if (request.action === 'save') await held;
      return current;
    },
  });
  edit(input, 'captured');
  const saving = parent.client.bridge.call('save');
  expect(parent.requests).toHaveLength(1);
  edit(input, 'newer');
  await Promise.resolve();
  release();
  await saving;
  expect(child.client.bridge.state.value).toBe('newer');
  await vi.advanceTimersByTimeAsync(299);
  expect(parent.requests).toHaveLength(1);
  await vi.advanceTimersByTimeAsync(1);
  expect(parent.requests.map((request) => request.action)).toEqual([
    'save',
    null,
  ]);
  expect(parent.requests[1]).toMatchObject({
    updates: { data: { value: 'newer' } },
  });
});

it('clears captured child debounces while a parent action waits behind another request', async () => {
  vi.useFakeTimers();
  let release: () => void = () => {};
  const held = new Promise<void>((resolve) => {
    release = resolve;
  });
  const { parent, input } = await fixture({
    timing: 'live',
    delay: 300,
    parentRenderless: true,
    childRenderless: true,
    parentServer: async (request, current) => {
      if (request.action === 'hold') await held;
      return current;
    },
  });
  const first = parent.client.bridge.call('hold');
  edit(input, 'queued edit');
  await Promise.resolve();
  const saving = parent.client.bridge.call('save');
  await vi.advanceTimersByTimeAsync(500);
  expect(parent.requests).toHaveLength(1);
  release();
  await Promise.all([first, saving]);
  await vi.runAllTimersAsync();
  expect(parent.requests.map((request) => request.action)).toEqual([
    'hold',
    'save',
  ]);
  expect(parent.requests[1]).toMatchObject({
    updates: { data: { value: 'queued edit' } },
  });
});

it('drops obsolete child debounces when a parent draft overrides their value', async () => {
  vi.useFakeTimers();
  const { parent, input } = await fixture({
    timing: 'live',
    delay: 300,
    parentRenderless: true,
  });
  edit(input, 'obsolete edit');
  await Promise.resolve();
  parent.client.bridge.state.data = { value: 'parent override', end: '' };
  await parent.client.bridge.call('save');
  await vi.advanceTimersByTimeAsync(500);
  expect(parent.requests.map((request) => request.action)).toEqual(['save']);
  expect(parent.requests[0]).toMatchObject({
    updates: { data: { value: 'parent override' } },
  });
});

it('does not flush a child debounce when a history snapshot omits that draft', async () => {
  vi.useFakeTimers();
  const { parent, input } = await fixture({
    timing: 'live',
    delay: 300,
    parentRenderless: true,
  });
  edit(input, 'unsent draft');
  await Promise.resolve();
  parent.client.composition?.begin(null, { data: { value: 'restored value' } });
  await vi.advanceTimersByTimeAsync(300);
  expect(parent.requests).toHaveLength(1);
  expect(parent.requests[0]).toMatchObject({
    updates: { data: { value: 'unsent draft' } },
  });
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

it('leaves deferred models unsent when focus exits the child', async () => {
  const { parent, input } = await fixture();
  edit(input, 'deferred selection');
  await vi.waitFor(() =>
    expect(parent.client.bridge.state.data).toMatchObject({
      value: 'deferred selection',
    }),
  );
  input.dispatchEvent(new FocusEvent('focusout', { bubbles: true }));
  expect(parent.requests).toHaveLength(0);
});

it('does not resume a paused parent when focus leaves a blur-bound child', async () => {
  const { parent, childRoot, input } = await fixture({
    timing: 'blur',
    parentServer: async () => {
      throw new Error('Offline');
    },
  });
  await parent.client.bridge.sync();
  edit(input, 'unsent selection');
  await vi.waitFor(() =>
    expect(parent.client.bridge.state.data).toMatchObject({
      value: 'unsent selection',
    }),
  );
  input.dispatchEvent(new FocusEvent('focusout', { bubbles: true }));
  expect(parent.client.bridge.paused).toBe(true);
  expect(parent.requests).toHaveLength(1);
  expect(childRoot.isConnected).toBe(true);
});

it('keeps local parent changes without automatically retrying a failed child', async () => {
  const { parent, child } = await fixture({
    parentServer: async (_request, current) => ({
      ...current,
      data: { value: 'parent replacement', end: '' },
    }),
    childServer: async () => {
      throw new Error('Offline');
    },
  });
  await child.client.bridge.sync();
  await parent.client.bridge.call('replace');
  await vi.waitFor(() =>
    expect(child.client.bridge.state.value).toBe('parent replacement'),
  );
  expect(child.client.bridge.paused).toBe(true);
  expect(child.requests).toHaveLength(1);
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

it('discovers child roots once while routing sibling tickets independently', async () => {
  const { parent, child, parentRoot, childRoot, relationships } =
    await fixture();
  const siblingRoot = root(
    'sibling',
    { value: '', end: '', notes: '' },
    {
      value: { type: 'string', nullable: false },
      end: { type: 'string', nullable: false },
      notes: { type: 'string', nullable: false },
    },
  );
  siblingRoot.dataset.interactRelation = childRoot.dataset.interactRelation;
  parentRoot.append(siblingRoot);
  const sibling = participant(siblingRoot, relationships);
  await Promise.resolve();
  const incoming = parentRoot.cloneNode(false);
  if (!(incoming instanceof HTMLElement))
    throw new Error('Missing replacement.');
  const discover = vi.spyOn(parentRoot, 'querySelectorAll');
  parent.client.composition?.accepted(incoming);
  expect(discover).not.toHaveBeenCalled();
  incoming.innerHTML =
    '<div data-interact-preserve="child" data-interact-parent-inputs="first-ticket"></div><div data-interact-preserve="sibling" data-interact-parent-inputs="second-ticket"></div>';
  parent.client.composition?.accepted(incoming);
  expect(discover).toHaveBeenCalledExactlyOnceWith('[data-interact-id]');
  await vi.waitFor(() => {
    expect(child.requests).toHaveLength(1);
    expect(sibling.requests).toHaveLength(1);
  });
  expect(child.requests[0]).toMatchObject({ parentInputs: 'first-ticket' });
  expect(sibling.requests[0]).toMatchObject({ parentInputs: 'second-ticket' });
  expect(parent.requests).toHaveLength(0);
});

it('sends accepted parent models with the input ticket in one coherent child update', async () => {
  const { parent, child } = await fixture({
    parentContent:
      '<div data-interact-preserve="child" data-interact-parent-inputs="signed-new"></div>',
    parentServer: async (_request, current) => ({
      ...current,
      data: { value: 'accepted selection', end: 'accepted end' },
    }),
  });
  await parent.client.bridge.call('replace');
  await vi.waitFor(() => expect(child.requests).toHaveLength(1));
  await vi.waitFor(() => expect(child.client.bridge.busy).toBe(false));
  expect(child.requests[0]).toMatchObject({
    parentInputs: 'signed-new',
    updates: { value: 'accepted selection', end: 'accepted end' },
  });
  expect(child.client.bridge.state).toMatchObject({
    value: 'accepted selection',
    end: 'accepted end',
  });
  expect(parent.client.bridge.state.data).toEqual({
    value: 'accepted selection',
    end: 'accepted end',
  });
  expect(parent.requests).toHaveLength(1);
  expect(child.requests).toHaveLength(1);
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
    '<div data-interact-preserve="absent" data-interact-parent-inputs="missing"></div><div data-interact-id="nested"><div data-interact-preserve="child" data-interact-parent-inputs="nested"></div></div><div data-interact-preserve="unregistered" data-interact-parent-inputs="orphan"></div><div data-interact-preserve="child" data-interact-parent-inputs=""></div>';
  parentRoot.append(root('unregistered', {}, {}));
  const nested = root('nested', {}, {});
  nested.append(root('child', {}, {}));
  parentRoot.prepend(nested);
  incoming.innerHTML +=
    '<div data-interact-preserve="child" data-interact-parent-inputs="shadowed"></div>';
  parent.client.composition?.accepted(incoming);
  child.client.dispose();
  child.client.receiveParentInputs('ignored');
  expect(child.requests).toHaveLength(0);
});

it.each(['identity', 'endpoint'])(
  'rejects a direct-parent %s mismatch',
  async (mismatch) => {
    const { parent, child, childRoot, relationships } = await fixture();
    child.client.dispose();
    if (mismatch === 'identity')
      parent.client.root.dataset.interactId = 'different';
    else childRoot.dataset.interactEndpoint = '/other/update';
    const replacement = participant(childRoot, relationships);
    await Promise.resolve();
    expect(replacement.client.bridge.error?.code).toBe('client_error');
    expect(replacement.requests).toHaveLength(0);
  },
);

it('does not connect a child disposed before its initialization microtask', async () => {
  const relationships = new Relationships(Alpine, (element) =>
    roots.get(element),
  );
  const element = root('removed', {}, {});
  document.body.append(element);
  const child = participant(element, relationships);
  child.client.dispose();
  await Promise.resolve();
  expect(child.client.bridge.error).toBeNull();
  expect(child.requests).toHaveLength(0);
});

it('reports a missing parent value while leaving a manual child uninitialized', async () => {
  const relationships = new Relationships(Alpine, (element) =>
    roots.get(element),
  );
  const parentRoot = root(
    'parent',
    { data: {} },
    { data: { type: 'array', nullable: false } },
  );
  document.body.append(parentRoot);
  participant(parentRoot, relationships);
  const childRoot = root('pending', {}, {});
  childRoot.dataset.interactLoading = 'manual';
  childRoot.dataset.interactRelation = JSON.stringify({
    parentId: 'parent',
    models: { value: { path: 'data.missing', timing: 'defer', delay: 250 } },
  });
  parentRoot.append(childRoot);
  const child = participant(childRoot, relationships);
  await Promise.resolve();
  expect(child.client.bridge.error?.code).toBe('client_error');
  expect(child.client.isMounted()).toBe(false);
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

it('reports structurally invalid relationship JSON through client feedback', async () => {
  const relationships = new Relationships(Alpine, (element) =>
    roots.get(element),
  );
  const element = root('invalid', {}, {});
  element.dataset.interactRelation = '{}';
  document.body.append(element);
  const child = participant(element, relationships);
  await Promise.resolve();
  expect(child.client.bridge.error?.code).toBe('client_error');
  expect(child.requests).toHaveLength(0);
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
  expect(child.client.composition?.updates()).toEqual({ end: '' });
});
