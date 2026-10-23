import { afterAll, afterEach, expect, it, vi } from 'vite-plus/test';
import { destroyBindings, mountClient } from './mount';
import type { IslandClient } from '../../resources/ts/client';
import type {
  Fetcher,
  IslandResponse,
  RenderlessResponse,
} from '../../resources/ts/types';
import type { UrlFields } from '../../resources/ts/url/types';
import {
  IslandRequestError,
  requestError,
} from '../../resources/ts/transport/failure';
import type { RequestLifecycleDetail } from '../../resources/ts/diagnostics/types';
import type {
  UploadMetadata,
  Uploader,
} from '../../resources/ts/uploads/types';
import { RequestLifecycle } from '../../resources/ts/diagnostics/request-lifecycle';
import { BrowserEffects } from '../../resources/ts/effects/browser';

const clients: IslandClient[] = [];
const lifetime = new AbortController();
const records: RequestLifecycleDetail[] = [];
const record = (event: CustomEvent<RequestLifecycleDetail>) =>
  records.push(event.detail);
document.addEventListener('interact:request-queued', record, {
  signal: lifetime.signal,
});
document.addEventListener('interact:request-started', record, {
  signal: lifetime.signal,
});
document.addEventListener('interact:request-response', record, {
  signal: lifetime.signal,
});
document.addEventListener('interact:request-applied', record, {
  signal: lifetime.signal,
});
document.addEventListener('interact:request-finished', record, {
  signal: lifetime.signal,
});
afterAll(() => lifetime.abort());

function root(): HTMLElement {
  const element = document.createElement('section');
  Object.assign(element.dataset, {
    interactId: 'observed',
    interactSnapshot: 'private-signature',
    interactCsrf: 'private-csrf',
    interactEndpoint: '/private-endpoint',
    interactState: JSON.stringify({ title: 'private-draft' }),
    interactWritable: JSON.stringify({
      title: { type: 'string', nullable: false },
    }),
  });
  document.body.append(element);
  return element;
}

function payload(
  element: HTMLElement,
  extra: Partial<IslandResponse | RenderlessResponse> = {},
): IslandResponse | RenderlessResponse {
  return {
    version: 1,
    id: 'observed',
    html: element.outerHTML,
    snapshot: 'private-next-signature',
    state: { title: 'accepted' },
    writable: { title: { type: 'string', nullable: false } },
    errors: {},
    events: [],
    ...extra,
  };
}

function response(
  element: HTMLElement,
  status = 200,
  extra: Partial<IslandResponse | RenderlessResponse> = {},
): Response {
  return new Response(JSON.stringify(payload(element, extra)), {
    status,
    headers: { 'Content-Type': 'application/json' },
  });
}

function setup(
  fetcher?: Fetcher,
  patch?: (from: HTMLElement, to: HTMLElement) => void,
) {
  const element = root();
  const client = mountClient(
    element,
    fetcher ?? (async () => response(element)),
    patch,
  );
  clients.push(client);
  return { element, client };
}

function finished() {
  return records.filter((detail) => detail.phase === 'finished');
}

afterEach(() => {
  for (const client of clients.splice(0)) {
    destroyBindings(client.root);
    client.dispose();
  }
  document.body.innerHTML = '';
  history.replaceState(null, '', '/');
  records.length = 0;
  vi.restoreAllMocks();
});

it('reports ordered phases, monotonic timings, and safe detached metadata before settlement', async () => {
  let now = 0;
  vi.spyOn(performance, 'now').mockImplementation(() => now);
  const { client, element } = setup(
    async () => {
      now = 20;
      return response(element);
    },
    () => {
      now = 50;
    },
  );
  element.addEventListener('interact:request-queued', () => {
    now = 5;
  });
  element.addEventListener('interact:request-started', () => {
    now = 8;
  });
  element.addEventListener('interact:request-response', () => {
    now = 35;
  });
  element.addEventListener('interact:request-applied', () => {
    now = 60;
  });
  let settled = false;
  element.addEventListener('interact:request-finished', () => {
    expect(settled).toBe(false);
    expect(client.bridge.busy).toBe(false);
    expect(client.bridge.pending()).toBe(false);
  });
  expect(await client.bridge.call('save', 'private-argument')).toEqual({
    ok: true,
  });
  settled = true;
  expect(records.map((detail) => detail.phase)).toEqual([
    'queued',
    'started',
    'response',
    'applied',
    'finished',
  ]);
  expect(new Set(records.map((detail) => detail.requestId)).size).toBe(1);
  expect(finished()[0]).toMatchObject({
    kind: 'action',
    action: 'save',
    islandId: 'observed',
    transport: 'fetch',
    outcome: 'success',
    httpStatus: 200,
    stage: 'apply',
    failure: null,
    timings: {
      queueMs: 5,
      prepareMs: 3,
      responseMs: 27,
      applyMs: 25,
      totalMs: 60,
    },
  });
  for (const detail of records) expect(Object.isFrozen(detail)).toBe(true);
  expect(Object.isFrozen(finished()[0]?.timings)).toBe(true);
  expect(JSON.stringify(records)).not.toContain('private-');
});

it('coalesces synchronization observations while preserving action order and distinct identities', async () => {
  let release = () => {};
  const held = new Promise<void>((resolve) => {
    release = resolve;
  });
  let calls = 0;
  const { client, element } = setup(async () => {
    if (++calls === 1) await held;
    return response(element);
  });
  const first = client.bridge.call('hold');
  const sync = client.bridge.sync();
  const sameSync = client.bridge.sync();
  expect(records.filter((detail) => detail.phase === 'queued')).toHaveLength(2);
  release();
  await Promise.all([first, sync, sameSync]);
  expect(calls).toBe(2);
  expect(finished().map((detail) => detail.kind)).toEqual(['action', 'sync']);
  expect(new Set(finished().map((detail) => detail.requestId)).size).toBe(2);
});

it.each([false, true])(
  'accepts validation and renderless application (renderless: %s)',
  async (renderless) => {
    const { client, element } = setup(async () =>
      response(element, renderless ? 200 : 422, {
        html: renderless ? null : element.outerHTML,
        errors: renderless ? {} : { title: ['Required'] },
      }),
    );
    expect(await client.bridge.call('save')).toEqual({ ok: true });
    expect(records.find((detail) => detail.phase === 'applied')).toMatchObject({
      rendered: !renderless,
      outcome: renderless ? 'success' : 'validation',
    });
    expect(finished()[0]?.outcome).toBe(renderless ? 'success' : 'validation');
  },
);

it('observes an entire validation stream once with its HTTP and logical statuses distinguished', async () => {
  const { client, element } = setup(
    async () =>
      new Response(
        `event: interact\ndata: ${JSON.stringify({ version: 1, id: 'observed', type: 'result', status: 422, payload: payload(element, { errors: { title: ['Required'] } }) })}\n\n`,
        { headers: { 'Content-Type': 'text/event-stream' } },
      ),
  );
  expect(await client.bridge.call('stream')).toEqual({ ok: true });
  expect(finished()).toHaveLength(1);
  expect(finished()[0]).toMatchObject({
    httpStatus: 200,
    outcome: 'validation',
    failure: null,
  });
});

it('finishes failed and discarded work once after publishing ordinary failure feedback', async () => {
  let release = () => {};
  const held = new Promise<void>((resolve) => {
    release = resolve;
  });
  const { client, element } = setup(async () => {
    await held;
    throw new TypeError('private-network-cause');
  });
  const feedback = vi.fn();
  element.addEventListener('interact:error', feedback);
  element.addEventListener('interact:request-finished', (event) => {
    if (event.detail.outcome === 'failed')
      expect(client.bridge.paused).toBe(true);
  });
  const first = client.bridge.call('save');
  const later = client.bridge.call('later');
  release();
  expect(await first).toMatchObject({
    ok: false,
    error: { code: 'network_error' },
  });
  expect(await later).toMatchObject({
    ok: false,
    error: { code: 'network_error' },
  });
  expect(feedback).toHaveBeenCalledTimes(1);
  expect(finished()).toMatchObject([
    {
      outcome: 'failed',
      stage: 'response',
      failure: { code: 'network_error' },
    },
    {
      outcome: 'cancelled',
      stage: 'queue',
      transport: null,
      timings: { prepareMs: null, responseMs: null, applyMs: null },
    },
  ]);
  expect(Object.isFrozen(finished()[0]?.failure)).toBe(true);
  expect(JSON.stringify(records)).not.toContain('private-');
});

it.each(['queued', 'started', 'response'] as const)(
  'reports cancellation when removed during %s notification',
  async (phase) => {
    const fetcher = vi.fn<Fetcher>();
    const { client, element } = setup(fetcher);
    fetcher.mockImplementation(async () => response(element));
    element.addEventListener(`interact:request-${phase}`, () => {
      element.remove();
      client.dispose();
    });
    expect(await client.bridge.call('save')).toMatchObject({
      ok: false,
      error: { code: 'cancelled' },
    });
    expect(records[0]?.phase).toBe('queued');
    expect(records.at(-1)).toMatchObject({
      phase: 'finished',
      outcome: 'cancelled',
    });
    expect(finished()).toHaveLength(1);
    expect(records.some((detail) => detail.phase === 'applied')).toBe(false);
    if (phase !== 'response') expect(fetcher).not.toHaveBeenCalled();
  },
);

it('does not let observers mutate metadata or turn listener exceptions into request failures', async () => {
  const { client, element } = setup();
  const errors: string[] = [];
  const capture = (event: ErrorEvent) => {
    errors.push(event.message);
    event.preventDefault();
  };
  window.addEventListener('error', capture);
  element.addEventListener('interact:request-started', (event) => {
    expect(event.cancelable).toBe(false);
    event.preventDefault();
    expect(Reflect.set(event.detail, 'action', 'changed')).toBe(false);
    throw new Error('observer failure');
  });
  try {
    expect(await client.bridge.call('save')).toEqual({ ok: true });
    expect(errors).toEqual(['observer failure']);
    expect(finished()[0]).toMatchObject({ action: 'save', outcome: 'success' });
    expect(client.bridge.paused).toBe(false);
  } finally {
    window.removeEventListener('error', capture);
  }
});

it('keeps each lifecycle ordered when queued and finished listeners enqueue more work', async () => {
  const { client, element } = setup();
  const calls: Promise<unknown>[] = [];
  element.addEventListener('interact:request-queued', (event) => {
    if (event.detail.action === 'first')
      calls.push(client.bridge.call('second'));
  });
  element.addEventListener('interact:request-finished', (event) => {
    if (event.detail.action === 'first')
      calls.push(client.bridge.call('third'));
  });
  await client.bridge.call('first');
  await Promise.all(calls);
  for (const detail of finished()) {
    expect(
      records
        .filter((record) => record.requestId === detail.requestId)
        .map((record) => record.phase),
    ).toEqual(['queued', 'started', 'response', 'applied', 'finished']);
  }
  expect(finished().map((detail) => detail.action)).toEqual([
    'first',
    'second',
    'third',
  ]);
});

it('finishes redirects without claiming an island application', async () => {
  vi.spyOn(BrowserEffects.prototype, 'navigate').mockImplementation(() => {});
  const { client } = setup(
    async () =>
      new Response(
        JSON.stringify({
          version: 1,
          id: 'observed',
          redirect: { url: '/next', replace: false },
        }),
        { headers: { 'Content-Type': 'application/json' } },
      ),
  );
  expect(await client.bridge.call('leave')).toEqual({ ok: true });
  expect(finished()[0]?.outcome).toBe('redirect');
  expect(records.some((detail) => detail.phase === 'applied')).toBe(false);
});

it('captures preparation and response application failures without leaking exceptions', async () => {
  const { client } = setup(undefined, () => {
    throw new Error('private-widget-error');
  });
  expect(await client.bridge.call('save')).toMatchObject({ ok: false });
  expect(finished()[0]).toMatchObject({
    outcome: 'failed',
    stage: 'apply',
    failure: { code: 'client_error' },
  });
  const trace = new RequestLifecycle(root(), 'direct', 'sync', null);
  trace.queued();
  trace.started();
  trace.failed(new Error('private-preparation'));
  trace.finish();
  trace.finish();
  expect(finished().at(-1)).toMatchObject({
    stage: 'prepare',
    outcome: 'failed',
    transport: null,
    failure: { code: 'client_error' },
  });
  expect(JSON.stringify(records)).not.toContain('private-');
});

it('whitelists failure fields and ignores non-string action values', () => {
  const failure = {
    ...requestError('network_error', null).failure,
    extra: 'private-error-property',
  };
  const trace = new RequestLifecycle(root(), 'observed', 'action', {
    secret: 'private-action',
  });
  trace.queued();
  trace.started();
  trace.failed(new IslandRequestError(failure));
  trace.finish();
  expect(finished()[0]).toMatchObject({
    action: null,
    outcome: 'failed',
    failure: { code: 'network_error' },
  });
  expect(JSON.stringify(records)).not.toContain('private-');
});

it('deduplicates manual initialization observations', async () => {
  const element = root();
  element.dataset.interactLoading = 'manual';
  element.dataset.interactState = '{}';
  element.dataset.interactWritable = '{}';
  const fetcher = vi.fn<Fetcher>(async () => response(element));
  const client = mountClient(element, fetcher);
  clients.push(client);
  await Promise.all([client.bridge.sync(), client.bridge.sync()]);
  expect(fetcher).toHaveBeenCalledTimes(1);
  expect(finished()).toHaveLength(1);
  expect(finished()[0]).toMatchObject({
    kind: 'initialize',
    action: null,
    outcome: 'success',
  });
});

it('observes implicit URL restoration without replaying an action', async () => {
  const element = root();
  const fields: UrlFields = {
    title: {
      name: 'q',
      type: 'string',
      default: 'private-draft',
      history: false,
    },
  };
  element.dataset.interactUrl = JSON.stringify(fields);
  const client = mountClient(element, async () =>
    response(element, 200, { url: fields }),
  );
  clients.push(client);
  await Promise.resolve();
  history.replaceState(null, '', '/?q=restored');
  window.dispatchEvent(new PopStateEvent('popstate'));
  await vi.waitFor(() => expect(finished()).toHaveLength(1));
  expect(finished()[0]).toMatchObject({
    kind: 'restore',
    action: null,
    outcome: 'success',
  });
});

it('distinguishes upload staging and removal while excluding file metadata', async () => {
  const element = root();
  const empty: UploadMetadata = {
    document: { multiple: false, maxSize: 1000, maxFiles: 1, files: [] },
  };
  const accepted: UploadMetadata = {
    document: {
      multiple: false,
      maxSize: 1000,
      maxFiles: 1,
      files: [{ id: 'a'.repeat(32), name: 'private-file.txt', size: 1 }],
    },
  };
  element.dataset.interactUploads = JSON.stringify(empty);
  element.innerHTML = '<input type="file" x-interact:model="document">';
  const uploader = vi.fn<Uploader>(async () =>
    response(element, 200, { uploads: accepted }),
  );
  const client = mountClient(
    element,
    async () => response(element, 200, { uploads: empty }),
    undefined,
    uploader,
  );
  clients.push(client);
  const input = element.querySelector('input');
  if (!input) throw new Error('Missing upload control');
  Object.defineProperty(input, 'files', {
    value: [new File(['x'], 'private-file.txt')],
    configurable: true,
  });
  input.dispatchEvent(new Event('change', { bubbles: true }));
  await vi.waitFor(() => expect(finished()).toHaveLength(1));
  expect(finished()[0]).toMatchObject({
    kind: 'upload-stage',
    transport: 'xhr',
    outcome: 'success',
  });
  const field = client.bridge.uploads.document;
  if (!field) throw new Error('Missing upload handle');
  await field.remove();
  expect(finished()[1]).toMatchObject({
    kind: 'upload-remove',
    transport: 'fetch',
    outcome: 'success',
  });
  expect(JSON.stringify(records)).not.toContain('private-');
});
