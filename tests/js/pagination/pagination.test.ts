import { afterEach, expect, it, vi } from 'vite-plus/test';
import { paginationLinks } from '../../../resources/ts/pagination/links';
import type { UrlFields } from '../../../resources/ts/url/types';
import type { IslandClient } from '../../../resources/ts/client';
import type { State, Writable } from '../../../resources/ts/types';
import { record, state } from '../../../resources/ts/state/values';
import { mountClient, destroyBindings } from '../support/mount';

const fields: UrlFields = {
  page: { name: 'p', type: 'int', default: 1, history: true },
  sort: { name: 'order', type: 'string', default: '', history: true },
  direction: { name: 'dir', type: 'string', default: 'asc', history: true },
  limit: { name: 'size', type: 'int', default: 5, history: false },
};
const writable: Writable = {
  page: { type: 'int', nullable: false },
  sort: { type: 'string', nullable: false },
  direction: { type: 'string', nullable: false },
  limit: { type: 'int', nullable: false },
  notes: { type: 'string', nullable: false },
};
const initial: State = {
  page: 1,
  sort: '',
  direction: 'asc',
  limit: 5,
  notes: '',
};
const clients: IslandClient[] = [];
const cleanups: (() => void)[] = [];

function fixture(targets: unknown = { '/catalog?p=2': { page: 2 } }) {
  history.replaceState(null, '', '/catalog');
  const root = document.createElement('div');
  Object.assign(root.dataset, {
    interactId: 'pages',
    interactSnapshot: 'signed',
    interactState: JSON.stringify(initial),
    interactWritable: JSON.stringify(writable),
    interactUrl: JSON.stringify(fields),
    interactEndpoint: '/_interact/update',
  });
  root.innerHTML =
    '<input x-interact:model="notes"><a href="/catalog?p=2"><span>Next</span></a>';
  const marker = document.createElement('template');
  marker.dataset.interactPagination = JSON.stringify(targets);
  root.append(marker);
  document.body.append(root);
  const link = root.querySelector('a');
  if (!link) throw new Error('Missing fixture link.');
  return { root, link, marker };
}
function click(element: Element, init: MouseEventInit = {}) {
  const event = new MouseEvent('click', {
    bubbles: true,
    cancelable: true,
    ...init,
  });
  element.dispatchEvent(event);
  return event;
}
function listen(root: HTMLElement, declared: Writable = writable) {
  const apply = vi.fn<(changes: State) => void>();
  const failed = vi.fn();
  cleanups.push(paginationLinks(root, () => declared, apply, failed));
  return { apply, failed };
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

it('enhances custom nested link markup using generated state and URL aliases', () => {
  const { root, link } = fixture({
    '/catalog?p=2': { page: 2, sort: 'title', direction: 'desc', limit: 10 },
  });
  const { apply } = listen(root);
  const span = link.querySelector('span');
  if (!span) throw new Error('Missing nested content.');
  expect(click(span).defaultPrevented).toBe(true);
  expect(apply).toHaveBeenCalledWith({
    page: 2,
    sort: 'title',
    direction: 'desc',
    limit: 10,
  });
});

it.each([
  { ctrlKey: true },
  { metaKey: true },
  { shiftKey: true },
  { altKey: true },
  { button: 1 },
])('retains native modified clicks: %j', (init) => {
  const { root, link } = fixture();
  const { apply } = listen(root);
  expect(click(link, init).defaultPrevented).toBe(false);
  expect(apply).not.toHaveBeenCalled();
});

it.each([
  ['target', '_blank'],
  ['download', 'rows.csv'],
  ['rel', 'external'],
  ['x-interact:navigate', ''],
  ['x-interact:navigate.hover', ''],
  ['href', 'https://other.test/catalog?p=2'],
  ['href', '/other?p=2'],
  ['href', '/catalog?p=2#results'],
  ['href', '/catalog?p=9'],
  ['href', '/catalog#interact-pagination-unregistered'],
])('leaves native destinations alone: %s=%s', (attribute, value) => {
  const { root, link } = fixture();
  link.setAttribute(attribute, value);
  const { apply } = listen(root);
  expect(click(link).defaultPrevented).toBe(false);
  expect(apply).not.toHaveBeenCalled();
});

it('respects application cancellation, nested ownership, and disposal', () => {
  const { root, link } = fixture();
  const { apply } = listen(root);
  link.addEventListener('click', (event) => event.preventDefault(), {
    once: true,
  });
  click(link);
  expect(apply).not.toHaveBeenCalled();
  const child = document.createElement('div');
  child.dataset.interactId = 'child';
  child.append(link);
  root.append(child);
  click(link);
  expect(apply).not.toHaveBeenCalled();
  root.append(link);
  cleanups.splice(0).forEach((cleanup) => cleanup());
  click(link);
  expect(apply).not.toHaveBeenCalled();
});

it('does not read another island’s generated targets or capture non-link clicks', () => {
  const { root, link, marker } = fixture();
  const child = document.createElement('div');
  child.dataset.interactId = 'child';
  child.append(marker);
  root.append(child);
  const { apply } = listen(root);
  click(link);
  click(root);
  const text = document.createTextNode('plain');
  root.append(text);
  text.dispatchEvent(new MouseEvent('click', { bubbles: true }));
  expect(apply).not.toHaveBeenCalled();
});

it.each([
  [],
  { '/catalog?p=2': { page: 0 } },
  { '/catalog?p=2': { page: 2.5 } },
  { '/catalog?p=2': { page: 2, limit: '10' } },
  { '/catalog?p=2': { page: 2, notes: 'injected' } },
  { '/catalog?p=2': { page: 2, sort: 9 } },
  { '/catalog?p=2': { page: 2, direction: false } },
  { '/catalog?p=2': null },
])('rejects invalid or undeclared paging changes: %j', (targets) => {
  const { root, link } = fixture(targets);
  const { apply, failed } = listen(root);
  expect(click(link).defaultPrevented).toBe(true);
  expect(apply).not.toHaveBeenCalled();
  expect(failed).toHaveBeenCalledOnce();
});

it('rejects malformed JSON and fields without writable ownership', () => {
  const { root, link, marker } = fixture();
  const { failed } = listen(root, {});
  click(link);
  expect(failed).toHaveBeenCalledOnce();
  marker.dataset.interactPagination = '{';
  click(link);
  expect(failed).toHaveBeenCalledTimes(2);
});

it('distinguishes state-only paging targets without URL declarations', () => {
  const next = '/catalog#interact-pagination-next';
  const last = '/catalog#interact-pagination-last';
  const { root, link } = fixture({
    [next]: { page: 2, sort: 'title', direction: 'asc', limit: 10 },
    [last]: { page: 12, sort: 'title', direction: 'asc', limit: 10 },
  });
  delete root.dataset.interactUrl;
  const { apply } = listen(root);
  link.href = next;
  expect(click(link).defaultPrevented).toBe(true);
  expect(apply).toHaveBeenLastCalledWith({
    page: 2,
    sort: 'title',
    direction: 'asc',
    limit: 10,
  });
  link.href = last;
  expect(click(link).defaultPrevented).toBe(true);
  expect(apply).toHaveBeenLastCalledWith({
    page: 12,
    sort: 'title',
    direction: 'asc',
    limit: 10,
  });
  expect(click(link, { ctrlKey: true }).defaultPrevented).toBe(false);
  expect(apply).toHaveBeenCalledTimes(2);
  expect(location.href).toBe('http://localhost:3000/catalog');
});

it('synchronizes state-only paging and unsent drafts without changing the URL or history', async () => {
  const target = '/catalog?external=keep#interact-pagination-next';
  const { root, link } = fixture({ [target]: { page: 2 } });
  delete root.dataset.interactUrl;
  history.replaceState(
    { application: 'retained' },
    '',
    '/catalog?external=keep#results',
  );
  const before = location.href;
  const push = vi.spyOn(history, 'pushState');
  const requests: unknown[] = [];
  const client = mountClient(root, async (_url, options) => {
    if (typeof options?.body !== 'string')
      throw new Error('Expected request JSON.');
    const request: unknown = JSON.parse(options.body);
    if (!record(request) || !state(request.updates))
      throw new Error('Missing edits.');
    requests.push(request);
    return new Response(
      JSON.stringify({
        version: 1,
        id: 'pages',
        html: root.outerHTML,
        snapshot: 'next',
        state: { ...initial, ...request.updates },
        writable,
        errors: {},
        events: [],
      }),
      { headers: { 'Content-Type': 'application/json' } },
    );
  });
  clients.push(client);
  const input = root.querySelector('input');
  if (!input) throw new Error('Missing notes input.');
  input.value = 'unsent draft';
  link.href = target;
  expect(click(link).defaultPrevented).toBe(true);
  await vi.waitFor(() => expect(client.bridge.state.page).toBe(2));
  await vi.waitFor(() => expect(client.bridge.busy).toBe(false));
  expect(requests).toEqual([
    expect.objectContaining({
      action: null,
      args: [],
      pageQuery: '?external=keep',
      updates: { page: 2, notes: 'unsent draft' },
    }),
  ]);
  expect(client.bridge.state.notes).toBe('unsent draft');
  expect(location.href).toBe(before);
  expect(history.state).toEqual({ application: 'retained' });
  expect(push).not.toHaveBeenCalled();
});

it('reports invalid generated targets through the client feedback', () => {
  const { root, link, marker } = fixture();
  const fetcher = vi.fn();
  const client = mountClient(root, fetcher);
  clients.push(client);
  marker.dataset.interactPagination = '{';
  expect(click(link).defaultPrevented).toBe(true);
  expect(fetcher).not.toHaveBeenCalled();
  expect(client.bridge.error).toBeTruthy();
});

it('queues native link clicks with unsent drafts and preserves newer edits across a held response', async () => {
  const { root, link, marker } = fixture({
    '/catalog?p=2': { page: 2 },
    '/catalog?p=3': { page: 3 },
  });
  const requests: Record<string, unknown>[] = [];
  let accepted = { ...initial };
  let release: () => void = () => {};
  const held = new Promise<void>((resolve) => {
    release = resolve;
  });
  const client = mountClient(root, async (_url, options) => {
    if (typeof options?.body !== 'string')
      throw new Error('Expected request JSON.');
    const request: unknown = JSON.parse(options.body);
    if (!record(request) || !state(request.updates))
      throw new Error('Expected state updates.');
    requests.push(request);
    accepted = { ...accepted, ...request.updates };
    const sent = { ...accepted };
    if (requests.length === 1) await held;
    return new Response(
      JSON.stringify({
        version: 1,
        id: 'pages',
        html: root.outerHTML,
        snapshot: 'next',
        state: sent,
        writable,
        url: fields,
        errors: {},
        events: [],
      }),
      { headers: { 'Content-Type': 'application/json' } },
    );
  });
  clients.push(client);
  const input = root.querySelector('input');
  if (!input) throw new Error('Missing notes input.');
  input.value = 'unsent draft';
  click(link);
  await vi.waitFor(() => expect(requests).toHaveLength(1));
  expect(requests[0]).toMatchObject({
    action: null,
    args: [],
    updates: { page: 2, notes: 'unsent draft' },
  });
  input.value = 'newer draft';
  input.dispatchEvent(new Event('input', { bubbles: true }));
  link.href = '/catalog?p=3';
  click(link);
  release();
  await vi.waitFor(() => expect(requests).toHaveLength(2));
  await vi.waitFor(() => expect(client.bridge.busy).toBe(false));
  expect(requests[1]).toMatchObject({
    action: null,
    updates: { page: 3, notes: 'newer draft' },
  });
  expect(client.bridge.state.page).toBe(3);
  expect(client.bridge.state.notes).toBe('newer draft');
  expect(location.search).toBe('?p=3');
  expect(marker.dataset.interactPagination).toContain('catalog');
});
