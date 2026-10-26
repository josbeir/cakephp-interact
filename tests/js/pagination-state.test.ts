import { afterEach, expect, it, vi } from 'vite-plus/test';
import { paginationLinks } from '../../resources/ts/pagination/links';
import { mergeChanges, read, write } from '../../resources/ts/state/paths';
import { state } from '../../resources/ts/state/values';
import { urlFields, urlState } from '../../resources/ts/url/codec';
import type { UrlFields } from '../../resources/ts/url/types';
import type { IslandClient } from '../../resources/ts/client';
import type { Fetcher, State, Writable } from '../../resources/ts/types';
import { destroyBindings, mountClient } from './mount';

const fields: UrlFields = {
  'first.page': { name: 'firstPage', type: 'int', default: 1, history: true },
  'first.sort': {
    name: 'firstSort',
    type: 'string',
    default: '',
    history: false,
  },
  'first.direction': {
    name: 'firstDirection',
    type: 'string',
    default: 'asc',
    history: false,
  },
  'first.limit': { name: 'firstSize', type: 'int', default: 5, history: true },
  'second.page': { name: 'secondPage', type: 'int', default: 1, history: true },
};
const writable: Writable = Object.fromEntries([
  ...Object.entries(fields).map(([name, field]) => [
    name,
    { type: field.type, nullable: false },
  ]),
  ['notes', { type: 'string', nullable: false }],
]);
const clients: IslandClient[] = [];
const cleanups: (() => void)[] = [];

function fixture() {
  history.replaceState({ app: 'keep' }, '', '/catalog?external=keep');
  const root = document.createElement('div');
  const initial: State = {
    first: { page: 1, sort: '', direction: 'asc', limit: 5 },
    second: { page: 1 },
    notes: '',
  };
  Object.assign(root.dataset, {
    interactId: 'nested-pages',
    interactSnapshot: 'signed',
    interactState: JSON.stringify(initial),
    interactWritable: JSON.stringify(writable),
    interactUrl: JSON.stringify(fields),
    interactEndpoint: '/_interact/update',
  });
  root.innerHTML = `
    <input aria-label="Notes" x-interact:model="notes">
    <select aria-label="Size" x-interact:model.change="first.limit"><option value="5">5</option><option value="10">10</option></select>
    <a href="/catalog?external=keep&firstPage=2">Next</a>
  `;
  const marker = document.createElement('template');
  marker.dataset.interactPagination = JSON.stringify({
    '/catalog?external=keep&firstPage=2': { 'first.page': 2 },
  });
  root.append(marker);
  document.body.append(root);
  return { root, initial, marker };
}

function request(options: RequestInit | undefined): State {
  if (typeof options?.body !== 'string')
    throw new Error('Missing request body.');
  const value: unknown = JSON.parse(options.body);
  if (!state(value) || !state(value.updates))
    throw new Error('Invalid request.');
  return value;
}

function reply(root: HTMLElement, accepted: State): Response {
  return new Response(
    JSON.stringify({
      version: 1,
      id: 'nested-pages',
      html: root.outerHTML,
      snapshot: 'next',
      state: accepted,
      writable,
      url: fields,
      errors: {},
      events: [],
    }),
    { headers: { 'Content-Type': 'application/json' } },
  );
}

function setup() {
  const { root, initial, marker } = fixture();
  let accepted = initial;
  const requests: State[] = [];
  const fetch: Fetcher = async (_url, options) => {
    const input = request(options);
    requests.push(input);
    if (!state(input.updates)) throw new Error('Invalid updates.');
    accepted = mergeChanges(accepted, input.updates);
    return reply(root, accepted);
  };
  const client = mountClient(root, fetch);
  clients.push(client);
  return { root, client, requests, marker };
}

afterEach(() => {
  for (const cleanup of cleanups.splice(0)) cleanup();
  for (const client of clients.splice(0)) {
    destroyBindings(client.root);
    client.dispose();
  }
  document.body.innerHTML = '';
  history.replaceState(null, '', '/');
  vi.restoreAllMocks();
});

it('captures typed nested page sizes and updates only their declared scalar paths', async () => {
  const { root, client, requests } = setup();
  const size = root.querySelector('select');
  if (!size) throw new Error('Missing size control.');
  size.value = '10';
  size.dispatchEvent(new Event('change', { bubbles: true }));
  await vi.waitFor(() => expect(requests).toHaveLength(1));
  expect(requests[0]).toMatchObject({ updates: { 'first.limit': 10 } });
  await vi.waitFor(() => expect(client.bridge.busy).toBe(false));
  expect(read(client.bridge.state, 'first.limit')).toBe(10);
  expect(read(client.bridge.state, 'second.page')).toBe(1);
  expect(new URL(location.href).searchParams.get('firstSize')).toBe('10');
  expect(history.state).toEqual({ app: 'keep' });
});

it('retains a newer sibling draft while accepting an in-flight page response', async () => {
  const { root, initial } = fixture();
  let complete: () => void = () => {};
  const requests: State[] = [];
  const fetch: Fetcher = (_url, options) => {
    const input = request(options);
    requests.push(input);
    if (!state(input.updates)) throw new Error('Invalid updates.');
    const accepted = mergeChanges(initial, input.updates);
    return new Promise<Response>((resolve) => {
      complete = () => resolve(reply(root, accepted));
    });
  };
  const client = mountClient(root, fetch);
  clients.push(client);
  write(client.bridge.state, 'first.page', 2);
  const pending = client.bridge.sync();
  await vi.waitFor(() => expect(requests).toHaveLength(1));
  write(client.bridge.state, 'first.sort', 'title');
  complete();
  await pending;
  expect(read(client.bridge.state, 'first.page')).toBe(2);
  expect(read(client.bridge.state, 'first.sort')).toBe('title');
  expect(read(client.bridge.state, 'second.page')).toBe(1);
  expect(client.bridge.dirty).toBe(true);
});

it('restores every owned URL path without discarding an unrelated unsent draft', async () => {
  const { root, client, requests } = setup();
  const notes = root.querySelector('input');
  if (!notes) throw new Error('Missing notes control.');
  notes.value = 'unsent draft';
  notes.dispatchEvent(new Event('input', { bubbles: true }));
  await Promise.resolve();
  history.replaceState(
    history.state,
    '',
    '/catalog?external=keep&firstPage=4&secondPage=2',
  );
  window.dispatchEvent(new PopStateEvent('popstate'));
  await vi.waitFor(() => expect(requests).toHaveLength(1));
  expect(requests[0]).toMatchObject({
    urlRestore: true,
    action: null,
    updates: { 'first.page': 4, 'second.page': 2 },
  });
  await vi.waitFor(() => expect(client.bridge.busy).toBe(false));
  expect(read(client.bridge.state, 'first.page')).toBe(4);
  expect(read(client.bridge.state, 'second.page')).toBe(2);
  expect(client.bridge.state.notes).toBe('unsent draft');
  expect(new URL(location.href).searchParams.get('external')).toBe('keep');
});

it('rejects helper metadata that mixes paging fields from different owners', () => {
  const { root, marker } = fixture();
  marker.dataset.interactPagination = JSON.stringify({
    '/catalog?external=keep&firstPage=2': { 'first.page': 2, 'second.page': 3 },
  });
  const apply = vi.fn();
  const failed = vi.fn();
  cleanups.push(paginationLinks(root, () => writable, apply, failed));
  root
    .querySelector('a')
    ?.dispatchEvent(
      new MouseEvent('click', { bubbles: true, cancelable: true }),
    );
  expect(apply).not.toHaveBeenCalled();
  expect(failed).toHaveBeenCalledOnce();
});

it('validates nested URL paths and refuses scalar or incomplete paging selections', () => {
  expect(urlFields(fields)).toBe(true);
  expect(urlFields({ 'first.__proto__.page': fields['first.page'] })).toBe(
    false,
  );
  expect(urlState({ first: 1, second: { page: 1 } }, fields)).toBe(false);
  expect(urlState({ first: { page: 1 }, second: { page: 1 } }, fields)).toBe(
    false,
  );
});
