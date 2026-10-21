import { afterEach, expect, it, vi } from 'vite-plus/test';
import { mountClient, destroyBindings } from './mount';
import { record, state } from '../../resources/ts/state/values';
import type {
  Fetcher,
  IslandResponse,
  State,
  Writable,
} from '../../resources/ts/types';
import type { UrlFields } from '../../resources/ts/url/types';
import type { IslandClient } from '../../resources/ts/client';

const fields: UrlFields = {
  search: { name: 'q', type: 'string', default: '', history: false },
  page: { name: 'page', type: 'int', default: 1, history: true },
};
const writable: Writable = {
  search: { type: 'string', nullable: false },
  page: { type: 'int', nullable: false },
  draft: { type: 'string', nullable: false },
};
const clients: IslandClient[] = [];
const initial: State = { search: '', page: 1, draft: '', calls: 0 };

function root(): HTMLElement {
  const element = document.createElement('div');
  Object.assign(element.dataset, {
    interactId: 'url-client',
    interactState: JSON.stringify(initial),
    interactWritable: JSON.stringify(writable),
    interactUrl: JSON.stringify(fields),
    interactSnapshot: 'signed',
    interactCsrf: 'csrf',
    interactEndpoint: '/_interact/update',
  });
  element.innerHTML =
    '<input aria-label="Search" x-interact:model="search"><textarea aria-label="Draft" x-interact:model="draft"></textarea>';
  document.body.append(element);
  return element;
}
function reply(
  element: HTMLElement,
  accepted: State,
  extra: Partial<IslandResponse> = {},
): Response {
  const payload: IslandResponse = {
    version: 1,
    id: 'url-client',
    html: element.outerHTML,
    snapshot: 'next',
    state: accepted,
    writable,
    errors: {},
    events: [],
    url: fields,
    ...extra,
  };
  return new Response(JSON.stringify(payload), {
    headers: { 'Content-Type': 'application/json' },
  });
}
function body(options: RequestInit | undefined): Record<string, unknown> {
  if (typeof options?.body !== 'string') throw new Error('Expected JSON body.');
  const value: unknown = JSON.parse(options.body);
  if (!record(value) || !state(value.updates))
    throw new Error('Expected state updates.');
  return value;
}
function setup(fetcher?: Fetcher) {
  const element = root();
  const calls: Record<string, unknown>[] = [];
  let accepted = { ...initial };
  const fetch: Fetcher =
    fetcher ??
    (async (_url, options) => {
      const request = body(options);
      calls.push(request);
      if (!state(request.updates)) throw new Error('Expected updates.');
      accepted = { ...accepted, ...request.updates };
      if (request.action === 'increment')
        accepted.calls = Number(accepted.calls) + 1;
      return reply(element, accepted);
    });
  const client = mountClient(element, fetch);
  clients.push(client);
  return { client, element, calls };
}
function navigate(search: string) {
  history.replaceState(history.state, '', `/search${search}`);
  window.dispatchEvent(new PopStateEvent('popstate'));
}
afterEach(() => {
  for (const client of clients.splice(0)) {
    destroyBindings(client.root);
    client.dispose();
  }
  document.body.innerHTML = '';
  history.replaceState(null, '', '/');
  vi.restoreAllMocks();
});

it('commits accepted values only and preserves unrelated drafts during history restoration', async () => {
  const { client, element, calls } = setup();
  await Promise.resolve();
  const input = element.querySelector('input');
  if (!input) throw new Error('Missing search input.');
  input.value = 'cake';
  input.dispatchEvent(new Event('input', { bubbles: true }));
  expect(location.search).toBe('');
  await client.bridge.sync();
  expect(location.search).toBe('?q=cake');
  const draft = element.querySelector('textarea');
  if (!draft) throw new Error('Missing draft.');
  draft.value = 'keep unsent';
  draft.dispatchEvent(new Event('input', { bubbles: true }));
  navigate('?q=earlier&page=3');
  await vi.waitFor(() => expect(client.bridge.pending()).toBe(false));
  expect(client.bridge.state).toMatchObject({
    search: 'earlier',
    page: 3,
    draft: 'keep unsent',
  });
  expect(calls.at(-1)).toMatchObject({
    urlRestore: true,
    updates: { search: 'earlier', page: 3 },
    action: null,
  });
  expect(calls.at(-1)?.updates).not.toHaveProperty('draft');
  expect(draft.value).toBe('keep unsent');
  const count = calls.length;
  navigate('?q=earlier&page=3&unrelated=one');
  await Promise.resolve();
  expect(calls).toHaveLength(count);
  client.bridge.state.page = 4;
  await client.bridge.sync();
  expect(location.search).toContain('page=4');
  expect(location.search).toContain('unrelated=one');
});

it('coalesces rapid Back/Forward and protects the latest target from older responses', async () => {
  let release: () => void = () => {};
  const held = new Promise<void>((resolve) => {
    release = resolve;
  });
  let number = 0;
  const requests: Record<string, unknown>[] = [];
  const element = root();
  const fetcher: Fetcher = async (_url, options) => {
    const request = body(options);
    requests.push(request);
    if (!state(request.updates)) throw new Error('Expected updates.');
    if (++number === 1) await held;
    return reply(element, { ...initial, ...request.updates });
  };
  const client = mountClient(element, fetcher);
  clients.push(client);
  await Promise.resolve();
  navigate('?q=first&page=2');
  navigate('?q=second&page=3');
  navigate('?q=latest&page=4');
  release();
  await vi.waitFor(() => expect(client.bridge.pending()).toBe(false));
  expect(requests).toHaveLength(2);
  expect(client.bridge.state.search).toBe('latest');
  expect(location.search).toBe('?q=latest&page=4');
});

it('finishes an accepted action once and then restores history without replaying it', async () => {
  let release: () => void = () => {};
  const held = new Promise<void>((resolve) => {
    release = resolve;
  });
  const element = root();
  let executions = 0;
  const fetcher: Fetcher = async (_url, options) => {
    const request = body(options);
    if (!state(request.updates)) throw new Error('Expected updates.');
    if (request.action === 'increment') {
      executions++;
      await held;
    }
    return reply(element, {
      ...initial,
      ...request.updates,
      calls: executions,
    });
  };
  const client = mountClient(element, fetcher);
  clients.push(client);
  await Promise.resolve();
  const action = client.bridge.call('increment');
  navigate('?q=earlier&page=3');
  release();
  await action;
  await vi.waitFor(() => expect(client.bridge.pending()).toBe(false));
  expect(client.bridge.state.search).toBe('earlier');
  expect(client.bridge.state.calls).toBe(1);
  expect(executions).toBe(1);
  expect(location.search).toBe('?q=earlier&page=3');
});

it('keeps failures explicit and permits a fresh synchronization of the selected URL', async () => {
  const element = root();
  let fail = true;
  let accepted = { ...initial };
  const fetcher: Fetcher = async (_url, options) => {
    const request = body(options);
    if (fail) return new Response('failed', { status: 500 });
    if (!state(request.updates)) throw new Error('Expected updates.');
    accepted = { ...accepted, ...request.updates };
    return reply(element, accepted);
  };
  const client = mountClient(element, fetcher);
  clients.push(client);
  await Promise.resolve();
  client.bridge.state.search = 'draft';
  await client.bridge.sync();
  expect(location.search).toBe('');
  navigate('?q=selected&page=2');
  await vi.waitFor(() => expect(client.bridge.pending()).toBe(false));
  expect(client.bridge.error?.code).toBe('server_error');
  expect(location.search).toBe('?q=selected&page=2');
  fail = false;
  await client.bridge.sync();
  expect(client.bridge.error).toBeNull();
  expect(client.bridge.state.search).toBe('selected');
});

it('does not bypass reload-required failures on Back or explicit synchronization', async () => {
  const fetch = vi.fn<Fetcher>(
    async () => new Response('forbidden', { status: 403 }),
  );
  const { client } = setup(fetch);
  await Promise.resolve();
  await client.bridge.sync();
  expect(fetch).toHaveBeenCalledTimes(1);
  navigate('?q=blocked');
  await client.bridge.sync();
  expect(fetch).toHaveBeenCalledTimes(1);
  expect(client.bridge.error?.code).toBe('forbidden');
});

it('validates URL metadata on initial mounts and subsequent responses', async () => {
  const invalid = root();
  invalid.dataset.interactUrl = 'null';
  expect(() => mountClient(invalid, async () => new Response())).toThrow(
    'Invalid initial URL metadata',
  );
  invalid.remove();
  const element = root();
  const fetcher: Fetcher = async () =>
    reply(element, initial, {
      url: {
        ...fields,
        search: {
          name: 'changed',
          type: 'string',
          default: '',
          history: false,
        },
      },
    });
  const client = mountClient(element, fetcher);
  clients.push(client);
  await client.bridge.sync();
  expect(client.bridge.error?.code).toBe('invalid_response');
});

it('publishes accepted URL values before dispatching application events', async () => {
  const element = root();
  const seen: string[] = [];
  element.addEventListener('saved', () => seen.push(location.search));
  const fetcher: Fetcher = async () =>
    reply(
      element,
      { ...initial, search: 'accepted' },
      { events: [{ name: 'saved', detail: {} }] },
    );
  const client = mountClient(element, fetcher);
  clients.push(client);
  await Promise.resolve();
  await client.bridge.call('save');
  expect(seen).toEqual(['?q=accepted']);
});

it('reports initial History API failures through normal island feedback', async () => {
  history.replaceState(null, '', '/search?q=obsolete');
  const broken = vi.spyOn(history, 'replaceState').mockImplementation(() => {
    throw new Error('history unavailable');
  });
  const { client } = setup();
  await Promise.resolve();
  expect(client.bridge.error?.code).toBe('client_error');
  broken.mockRestore();
});
