import {
  afterEach,
  beforeEach,
  describe,
  expect,
  it,
  vi,
} from 'vite-plus/test';
import Alpine from '@alpinejs/csp';
import {
  destroyBindings,
  mountClient,
  refreshBindings,
} from '../support/mount';
import type { IslandClient } from '../../../resources/ts/client';
import type { Fetcher, IslandResponse } from '../../../resources/ts/types';

const clients: IslandClient[] = [];
const initial = { title: 'original', running: true, progress: 0 };

function element(content: string, id = 'polling'): HTMLDivElement {
  const root = document.createElement('div');
  Object.assign(root.dataset, {
    interactId: id,
    interactSnapshot: 'signed',
    interactEndpoint: '/_interact/update',
    interactCsrf: 'csrf',
    interactState: JSON.stringify(initial),
    interactWritable: '{"title":{"type":"string","nullable":false}}',
  });
  root.innerHTML = content;
  document.body.append(root);
  return root;
}

function response(
  root: HTMLElement,
  changes: Partial<IslandResponse> = {},
): Response {
  const payload: IslandResponse = {
    version: 1,
    id: root.dataset.interactId ?? '',
    snapshot: 'next-signed',
    html: root.outerHTML,
    state: { ...initial },
    writable: { title: { type: 'string', nullable: false } },
    errors: {},
    events: [],
    ...changes,
  };
  return new Response(JSON.stringify(payload), {
    status: Object.keys(payload.errors).length ? 422 : 200,
    headers: { 'Content-Type': 'application/json' },
  });
}

function mount(
  root: HTMLElement,
  fetcher = vi.fn<Fetcher>(async () => response(root)),
) {
  const client = mountClient(root, fetcher);
  clients.push(client);
  return { client, fetcher };
}

function input(root: HTMLElement): HTMLInputElement {
  const found = root.querySelector('input');
  if (!found) throw new Error('Missing model input.');
  return found;
}

beforeEach(() => {
  vi.useFakeTimers();
  vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('visible');
});
afterEach(() => {
  clients.splice(0).forEach((client) => {
    destroyBindings(client.root);
    client.dispose();
  });
  for (const root of document.querySelectorAll<HTMLElement>(
    '[data-interact-id]',
  ))
    Alpine.destroyTree(root);
  document.body.innerHTML = '';
  vi.restoreAllMocks();
  vi.useRealTimers();
});

describe('polling through the Alpine bridge', () => {
  it('keeps mount-time feedback until an explicit successful request clears it', async () => {
    const root = element('<section x-interact:poll.100ms></section>');
    root.dataset.interactErrors = '{"title":["Required"]}';
    const { client, fetcher } = mount(root);
    await vi.advanceTimersByTimeAsync(500);
    expect(client.bridge.errors).toEqual({ title: ['Required'] });
    expect(fetcher).not.toHaveBeenCalled();
    await client.bridge.call('clear');
    expect(client.bridge.errors).toEqual({});
    expect(fetcher).toHaveBeenCalledOnce();
    await vi.advanceTimersByTimeAsync(100);
    expect(fetcher).toHaveBeenCalledTimes(2);
  });

  it('refreshes through the normal CSRF-protected transport without changing the protocol', async () => {
    const root = element('<section x-interact:poll.100ms></section>');
    const { fetcher } = mount(root);
    await vi.advanceTimersByTimeAsync(99);
    expect(fetcher).not.toHaveBeenCalled();
    await vi.advanceTimersByTimeAsync(1);
    expect(fetcher).toHaveBeenCalledOnce();
    const options = fetcher.mock.calls[0]?.[1];
    expect(options?.headers).toMatchObject({
      Interact: 'true',
      'X-CSRF-Token': 'csrf',
    });
    expect(options?.credentials).toBe('same-origin');
    expect(options?.body).toBe(
      JSON.stringify({
        version: 1,
        snapshot: 'signed',
        updates: {},
        action: null,
        args: [],
      }),
    );
    await vi.advanceTimersByTimeAsync(100);
    expect(fetcher).toHaveBeenCalledTimes(2);
  });

  it('uses current Alpine scope and stops requesting when PHP marks the job complete', async () => {
    const root = element(
      '<section x-data="{ enabled: false, jobId: 42 }" x-interact:poll.100ms="enabled && $server.state.running && $server.call(\'refreshStatus\', jobId)"></section>',
    );
    const { fetcher } = mount(root);
    fetcher.mockImplementation(async () =>
      response(root, { state: { ...initial, running: false, progress: 100 } }),
    );
    await vi.advanceTimersByTimeAsync(300);
    expect(fetcher).not.toHaveBeenCalled();
    const section = root.querySelector('section');
    if (!section) throw new Error('Missing polling section.');
    Alpine.evaluateRaw(section, 'enabled = true');
    await vi.advanceTimersByTimeAsync(100);
    expect(fetcher).toHaveBeenCalledOnce();
    expect(fetcher.mock.calls[0]?.[1]?.body).toContain(
      '"action":"refreshStatus","args":[42]',
    );
    await vi.advanceTimersByTimeAsync(500);
    expect(fetcher).toHaveBeenCalledOnce();
  });

  it('captures unreported control changes but never sends a dirty deferred draft', async () => {
    const root = element(
      '<section x-interact:poll.100ms><input x-interact:model="title"></section>',
    );
    const { client, fetcher } = mount(root);
    input(root).value = 'unreported draft';
    await vi.advanceTimersByTimeAsync(400);
    expect(client.bridge.state.title).toBe('unreported draft');
    expect(fetcher).not.toHaveBeenCalled();
    fetcher.mockImplementation(async () =>
      response(root, { state: { ...initial, title: 'unreported draft' } }),
    );
    await client.bridge.sync();
    expect(client.bridge.dirty).toBe(false);
    await vi.advanceTimersByTimeAsync(100);
    expect(fetcher).toHaveBeenCalledTimes(2);
  });

  it('leaves scheduled model synchronization alone even when the value equals its baseline', async () => {
    const root = element(
      '<section x-interact:poll.100ms><input x-interact:model.live.debounce.500ms="title"></section>',
    );
    const { fetcher } = mount(root);
    input(root).dispatchEvent(new Event('input', { bubbles: true }));
    await vi.advanceTimersByTimeAsync(499);
    expect(fetcher).not.toHaveBeenCalled();
    await vi.advanceTimersByTimeAsync(1);
    expect(fetcher).toHaveBeenCalledOnce();
    await vi.advanceTimersByTimeAsync(100);
    expect(fetcher).toHaveBeenCalledTimes(2);
  });

  it('holds validation feedback until an explicit interaction clears it', async () => {
    const root = element('<section x-interact:poll.100ms></section>');
    const { client, fetcher } = mount(root);
    fetcher.mockImplementationOnce(async () =>
      response(root, { errors: { title: ['Required.'] } }),
    );
    await vi.advanceTimersByTimeAsync(100);
    expect(client.bridge.errors).toEqual({ title: ['Required.'] });
    expect(client.bridge.paused).toBe(false);
    await vi.advanceTimersByTimeAsync(500);
    expect(fetcher).toHaveBeenCalledOnce();
    await client.bridge.sync();
    await vi.advanceTimersByTimeAsync(100);
    expect(fetcher).toHaveBeenCalledTimes(3);
  });

  it('does not queue polling behind active or queued manual actions', async () => {
    const root = element('<section x-interact:poll.100ms></section>');
    let finish = () => {};
    const held = new Promise<Response>((resolve) => {
      finish = () => resolve(response(root));
    });
    const { client, fetcher } = mount(root);
    fetcher.mockReturnValueOnce(held);
    const first = client.bridge.call('first');
    const second = client.bridge.call('second');
    await vi.advanceTimersByTimeAsync(1000);
    expect(fetcher).toHaveBeenCalledOnce();
    finish();
    await Promise.all([first, second]);
    expect(fetcher).toHaveBeenCalledTimes(2);
    await vi.advanceTimersByTimeAsync(100);
    expect(fetcher).toHaveBeenCalledTimes(3);
  });

  it('preserves a newer draft typed during an in-flight polling response', async () => {
    const root = element(
      '<section x-interact:poll.100ms><input x-interact:model="title"></section>',
    );
    let finish = () => {};
    const held = new Promise<Response>((resolve) => {
      finish = () => resolve(response(root));
    });
    const { client, fetcher } = mount(root);
    fetcher.mockReturnValueOnce(held);
    await vi.advanceTimersByTimeAsync(100);
    input(root).value = 'newer draft';
    input(root).dispatchEvent(new Event('input', { bubbles: true }));
    finish();
    await vi.advanceTimersByTimeAsync(500);
    expect(input(root).value).toBe('newer draft');
    expect(client.bridge.dirty).toBe(true);
    expect(fetcher).toHaveBeenCalledOnce();
  });

  it('pauses network failures without replaying and resumes after explicit recovery', async () => {
    const root = element('<section x-interact:poll.100ms></section>');
    const { client, fetcher } = mount(root);
    fetcher.mockRejectedValueOnce(new TypeError('Offline.'));
    const failure = vi.fn();
    root.addEventListener('interact:error', failure);
    await vi.advanceTimersByTimeAsync(100);
    expect(client.bridge.error?.code).toBe('network_error');
    await vi.advanceTimersByTimeAsync(1000);
    expect(fetcher).toHaveBeenCalledOnce();
    expect(failure).toHaveBeenCalledOnce();
    await client.bridge.sync();
    await vi.advanceTimersByTimeAsync(100);
    expect(fetcher).toHaveBeenCalledTimes(3);
    expect(client.bridge.paused).toBe(false);
  });

  it.each(['missingFunction()', 'fail()'])(
    'routes polling expression errors through normal feedback: %s',
    async (expression) => {
      Alpine.data('rejectingPoll', () => ({
        fail: () => Promise.reject(new Error('Private diagnostics.')),
      }));
      const root = element('<section x-data="rejectingPoll"></section>');
      const section = root.querySelector('section');
      if (!section) throw new Error('Missing polling section.');
      section.setAttribute('x-interact:poll.100ms', expression);
      const { client, fetcher } = mount(root);
      await vi.advanceTimersByTimeAsync(100);
      expect(client.bridge.error?.code).toBe('client_error');
      expect(client.bridge.error?.message).not.toContain(
        'Private diagnostics.',
      );
      await vi.advanceTimersByTimeAsync(500);
      expect(fetcher).not.toHaveBeenCalled();
    },
  );

  it('reports invalid captured controls without sending a poll', async () => {
    const root = element(
      '<section x-interact:poll.100ms><input x-interact:model="locked"></section>',
    );
    const { client, fetcher } = mount(root);
    await vi.advanceTimersByTimeAsync(100);
    expect(client.bridge.error?.code).toBe('client_error');
    expect(fetcher).not.toHaveBeenCalled();
  });

  it('rebinds changed polling declarations and releases removed or moved ones', async () => {
    const root = element('<section x-interact:poll.100ms></section>');
    const { fetcher } = mount(root);
    const section = root.querySelector('section');
    if (!section) throw new Error('Missing polling section.');
    destroyBindings(root);
    section.removeAttribute('x-interact:poll.100ms');
    section.setAttribute('x-interact:poll.200ms', '');
    refreshBindings(root);
    await vi.advanceTimersByTimeAsync(100);
    expect(fetcher).not.toHaveBeenCalled();
    const foreign = document.createElement('div');
    foreign.dataset.interactId = 'other-owner';
    root.append(foreign);
    foreign.append(section);
    await vi.advanceTimersByTimeAsync(1000);
    expect(fetcher).not.toHaveBeenCalled();
    destroyBindings(section);
    section.remove();
    await vi.advanceTimersByTimeAsync(1000);
    expect(fetcher).not.toHaveBeenCalled();
  });

  it('cleans up when the client is disposed during a poll', async () => {
    const root = element('<section x-interact:poll.100ms></section>');
    let finish = () => {};
    const held = new Promise<Response>((resolve) => {
      finish = () => resolve(response(root));
    });
    const { client, fetcher } = mount(root);
    fetcher.mockReturnValueOnce(held);
    await vi.advanceTimersByTimeAsync(100);
    client.dispose();
    finish();
    await vi.advanceTimersByTimeAsync(1000);
    expect(fetcher).toHaveBeenCalledOnce();
    expect(client.bridge.error).toBeNull();
  });

  it('rejects duplicate declarations within one island', () => {
    const root = element(
      '<section x-interact:poll></section><aside x-interact:poll></aside>',
    );
    expect(() => mount(root)).toThrow('one x-interact:poll');
  });
});
