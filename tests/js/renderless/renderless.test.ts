import { afterEach, expect, it, vi } from 'vite-plus/test';
import { mountClient, destroyBindings } from '../support/mount';
import type { IslandClient } from '../../../resources/ts/client';
import type { Fetcher, RenderlessResponse } from '../../../resources/ts/types';
import { BrowserEffects } from '../../../resources/ts/effects/browser';
import { response } from '../../../resources/ts/transport/protocol';
import type { UrlFields } from '../../../resources/ts/url/types';

const clients: IslandClient[] = [];
function accepted(extra: Partial<RenderlessResponse> = {}): RenderlessResponse {
  return {
    version: 1,
    id: 'renderless',
    html: null,
    snapshot: 'next',
    state: { title: 'accepted', count: 1 },
    writable: { title: { type: 'string', nullable: false } },
    errors: {},
    events: [],
    ...extra,
  };
}
function json(value: unknown, status = 200): Response {
  return new Response(JSON.stringify(value), {
    status,
    headers: { 'Content-Type': 'application/json' },
  });
}
function setup(fetcher: Fetcher, metadata: Record<string, string> = {}) {
  const root = document.createElement('section');
  Object.assign(root.dataset, {
    interactId: 'renderless',
    interactState: '{"title":"initial","count":0}',
    interactWritable: '{"title":{"type":"string","nullable":false}}',
    interactSnapshot: 'initial-snapshot',
    interactEndpoint: '/update',
    ...metadata,
  });
  root.innerHTML = metadata.interactLoading
    ? '<p>Pending initialization</p>'
    : '<form x-interact:submit="$server.call(\'save\')"><input aria-label="Title" x-interact:model="title"><button>Save</button></form><p>PHP markup</p>';
  document.body.append(root);
  const patch = vi.fn();
  const client = mountClient(root, fetcher, patch);
  clients.push(client);
  return { root, client, patch };
}
afterEach(() => {
  for (const client of clients.splice(0)) {
    destroyBindings(client.root);
    client.dispose();
  }
  document.body.replaceChildren();
  window.history.replaceState(null, '', '/');
  vi.restoreAllMocks();
});

it('accepts state and effects without patching and uses the fresh snapshot on the next request', async () => {
  const fetcher = vi.fn<Fetcher>(async () =>
    json(accepted({ events: [{ name: 'saved', detail: { count: 1 } }] })),
  );
  const { root, client, patch } = setup(fetcher);
  const input = root.querySelector('input');
  if (!input) throw new Error('Missing input.');
  const event = vi.fn(() => expect(client.bridge.state.count).toBe(1));
  root.addEventListener('saved', event);
  input.focus();
  input.setSelectionRange(1, 3);
  await expect(client.bridge.call('save')).resolves.toEqual({ ok: true });
  expect(patch).not.toHaveBeenCalled();
  expect(root.querySelector('input')).toBe(input);
  expect(input.value).toBe('accepted');
  expect(root.querySelector('p')?.textContent).toBe('PHP markup');
  expect(document.activeElement).toBe(input);
  expect(input.selectionStart).toBe(1);
  expect(event).toHaveBeenCalledOnce();
  expect(client.bridge.dirty).toBe(false);
  expect(root.dataset.interactSnapshot).toBe('next');
  await client.bridge.call('again');
  const body = fetcher.mock.calls[1]?.[1].body;
  if (typeof body !== 'string') throw new Error('Missing JSON request body.');
  expect(JSON.parse(body)).toMatchObject({
    snapshot: 'next',
    updates: {},
  });
});

it('preserves newer drafts and focus while acknowledging only the submitted values', async () => {
  let release: (response: Response) => void = () => {};
  const { root, client, patch } = setup(
    () =>
      new Promise((resolve) => {
        release = resolve;
      }),
  );
  const input = root.querySelector('input');
  if (!input) throw new Error('Missing input.');
  input.value = 'submitted';
  input.dispatchEvent(new Event('input', { bubbles: true }));
  const action = client.bridge.call('save');
  input.value = 'newer draft';
  input.dispatchEvent(new Event('input', { bubbles: true }));
  input.focus();
  input.setSelectionRange(3, 7);
  release(json(accepted({ state: { title: 'submitted', count: 1 } })));
  await action;
  expect(client.bridge.state.title).toBe('newer draft');
  expect(input.value).toBe('newer draft');
  expect(input.selectionStart).toBe(3);
  expect(input.selectionEnd).toBe(7);
  expect(client.bridge.dirty).toBe(true);
  expect(patch).not.toHaveBeenCalled();
});

it('keeps native form submission protected until a renderless action settles and unlocks afterwards', async () => {
  let release: (response: Response) => void = () => {};
  const fetcher = vi.fn<Fetcher>(
    () =>
      new Promise((resolve) => {
        release = resolve;
      }),
  );
  const { root, client } = setup(fetcher);
  const form = root.querySelector('form');
  const button = root.querySelector('button');
  if (!form || !button) throw new Error('Missing form.');
  form.dispatchEvent(
    new SubmitEvent('submit', {
      bubbles: true,
      cancelable: true,
      submitter: button,
    }),
  );
  form.dispatchEvent(
    new SubmitEvent('submit', {
      bubbles: true,
      cancelable: true,
      submitter: button,
    }),
  );
  await vi.waitFor(() => expect(fetcher).toHaveBeenCalledOnce());
  expect(button.disabled).toBe(true);
  release(json(accepted()));
  await vi.waitFor(() => expect(button.disabled).toBe(false));
  expect(client.bridge.pending()).toBe(false);
});

it.each([
  { html: undefined },
  { html: '', snapshot: '' },
  { html: 0 },
  { errors: { title: ['Required'] } },
  { snapshot: '' },
  { state: { constructor: 'unsafe' } },
])(
  'rejects malformed state-only envelopes before applying effects: %j',
  (extra) => {
    expect(response({ ...accepted(), ...extra })).toBe(false);
  },
);

it.each([200, 422])(
  'rejects renderless synchronization responses with HTTP %s',
  async (status) => {
    const { client, patch } = setup(async () => json(accepted(), status));
    await expect(client.bridge.sync()).resolves.toMatchObject({
      ok: false,
      error: { code: 'invalid_response' },
    });
    expect(patch).not.toHaveBeenCalled();
    expect(client.bridge.state.count).toBe(0);
  },
);

it('orders native downloads and events after accepting a renderless action', async () => {
  const order: string[] = [];
  vi.spyOn(BrowserEffects.prototype, 'download').mockImplementation(() => {
    order.push('download');
  });
  const { root, client, patch } = setup(async () =>
    json(
      accepted({
        download: '/report',
        events: [{ name: 'saved', detail: {} }],
      }),
    ),
  );
  root.addEventListener('saved', () => {
    expect(client.bridge.state.count).toBe(1);
    order.push('event');
  });
  await client.bridge.call('download');
  expect(order).toEqual(['download', 'event']);
  expect(patch).not.toHaveBeenCalled();
});

it('discards late responses after disposal without emitting events', async () => {
  let release: (response: Response) => void = () => {};
  const { root, client } = setup(
    () =>
      new Promise((resolve) => {
        release = resolve;
      }),
  );
  const event = vi.fn();
  root.addEventListener('saved', event);
  const action = client.bridge.call('save');
  client.dispose();
  release(json(accepted({ events: [{ name: 'saved', detail: {} }] })));
  await expect(action).resolves.toMatchObject({
    ok: false,
    error: { code: 'cancelled' },
  });
  expect(event).not.toHaveBeenCalled();
});

it('rejects renderless initialization without activating the pending island', async () => {
  const { client, patch, root } = setup(async () => json(accepted()), {
    interactLoading: 'manual',
    interactState: '{}',
    interactWritable: '{}',
  });
  await expect(client.bridge.sync()).resolves.toMatchObject({
    ok: false,
    error: { code: 'invalid_response' },
  });
  expect(patch).not.toHaveBeenCalled();
  expect(root.dataset.interactSnapshot).toBe('initial-snapshot');
});

it('requires HTTP 200 for renderless actions', async () => {
  const { client, patch } = setup(async () => json(accepted(), 422));
  await expect(client.bridge.call('save')).resolves.toMatchObject({
    ok: false,
    error: { code: 'invalid_response' },
  });
  expect(patch).not.toHaveBeenCalled();
});

it('publishes accepted URL state while preserving unrelated parameters and history', async () => {
  history.replaceState({ application: 'retained' }, '', '/?other=keep');
  const url: UrlFields = {
    title: { name: 'q', type: 'string', default: '', history: true },
  };
  const { client, patch } = setup(async () => json(accepted({ url })), {
    interactUrl: JSON.stringify(url),
  });
  await client.bridge.call('save');
  expect(location.search).toContain('q=accepted');
  expect(location.search).toContain('other=keep');
  expect(history.state).toMatchObject({ application: 'retained' });
  expect(patch).not.toHaveBeenCalled();
});

it('accepts null, false, zero, empty strings, and nested values without coercion', async () => {
  const state = {
    title: '',
    count: 0,
    nullable: null,
    enabled: false,
    data: { checked: false, values: ['', '0'] },
  };
  const { client, root, patch } = setup(async () => json(accepted({ state })), {
    interactState: JSON.stringify({
      ...state,
      nullable: 'old',
      enabled: true,
      count: 1,
    }),
  });
  await client.bridge.call('save');
  expect(client.bridge.state).toEqual(state);
  expect(JSON.parse(root.dataset.interactState ?? '{}')).toEqual(state);
  expect(patch).not.toHaveBeenCalled();
});

it('refreshes owned file metadata and keeps uploads out of ordinary state', async () => {
  const uploads = {
    document: {
      multiple: false,
      maxSize: 100,
      maxFiles: 1,
      files: [{ id: 'a'.repeat(32), name: 'draft.txt', size: 5 }],
    },
  };
  const { client, root } = setup(async () => json(accepted({ uploads })), {
    interactUploads: JSON.stringify({
      document: { ...uploads.document, files: [] },
    }),
  });
  await client.bridge.call('save');
  expect(client.bridge.uploads.document?.files).toMatchObject(
    uploads.document.files,
  );
  expect(client.bridge.state).not.toHaveProperty('document');
  expect(JSON.parse(root.dataset.interactUploads ?? '{}')).toEqual(uploads);
});

it('queues actions with the latest accepted baseline and does not replay them', async () => {
  let release: (response: Response) => void = () => {};
  const fetcher = vi
    .fn<Fetcher>()
    .mockImplementationOnce(
      () =>
        new Promise((resolve) => {
          release = resolve;
        }),
    )
    .mockImplementationOnce(async () =>
      json(
        accepted({ snapshot: 'last', state: { title: 'accepted', count: 2 } }),
      ),
    );
  const { client } = setup(fetcher);
  const first = client.bridge.call('save');
  const second = client.bridge.call('save');
  expect(fetcher).toHaveBeenCalledOnce();
  release(json(accepted()));
  await Promise.all([first, second]);
  const body = fetcher.mock.calls[1]?.[1].body;
  if (typeof body !== 'string') throw new Error('Missing JSON request body.');
  expect(JSON.parse(body)).toMatchObject({
    snapshot: 'next',
    action: 'save',
    updates: {},
  });
  expect(fetcher).toHaveBeenCalledTimes(2);
  expect(client.bridge.state.count).toBe(2);
});
