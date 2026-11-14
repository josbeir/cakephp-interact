import {
  afterEach,
  beforeEach,
  describe,
  expect,
  it,
  vi,
} from 'vite-plus/test';
import { BrowserEffects } from '../../../resources/ts/effects/browser';
import {
  mountClient,
  destroyBindings,
  refreshBindings,
} from '../support/mount';
import type { IslandClient } from '../../../resources/ts/client';
import type {
  Fetcher,
  Patcher,
  RequestResult,
} from '../../../resources/ts/types';

const clients: IslandClient[] = [];

beforeEach(() => {
  vi.spyOn(window, 'confirm').mockReturnValue(false);
  document.dispatchEvent(new Event('interact:navigated'));
});

/**
 * Read a captured request body only after checking the transport sent JSON text.
 */
function requestBody(options: RequestInit | undefined): unknown {
  const body = options?.body;
  if (typeof body !== 'string')
    throw new Error('Expected a JSON request body.');
  return JSON.parse(body);
}

function root(): HTMLDivElement {
  const element = document.createElement('div');
  Object.assign(element.dataset, {
    interactId: 'recovery',
    interactSnapshot: 'original',
    interactEndpoint: '/update',
    interactState: JSON.stringify({
      title: 'original',
      locked: 'private server state',
      form: { enabled: false, zero: 0, empty: '', value: null },
    }),
    interactWritable: JSON.stringify({
      title: { type: 'string', nullable: false },
      form: { type: 'array', nullable: false },
    }),
  });
  element.innerHTML =
    '<form x-interact:submit="$server.call(\'save\')"><input value="original" x-interact:model.live.debounce.30ms="title"><button type="submit">Save</button></form>';
  document.body.append(element);
  return element;
}
function input(element: HTMLElement): HTMLInputElement {
  const found = element.querySelector('input');
  if (!(found instanceof HTMLInputElement)) throw new Error('Missing input');
  return found;
}
function edit(element: HTMLElement, value: string): void {
  input(element).value = value;
  input(element).dispatchEvent(new Event('input', { bubbles: true }));
}
function success(element: HTMLElement, title: string, status = 200): Response {
  return new Response(
    JSON.stringify({
      version: 1,
      id: 'recovery',
      html: element.outerHTML,
      snapshot: 'accepted',
      state: {
        title,
        locked: 'server',
        form: { enabled: false, zero: 0, empty: '', value: null },
      },
      writable: JSON.parse(element.dataset.interactWritable ?? '{}'),
      errors: status === 422 ? { title: ['Required'] } : {},
      events: [],
    }),
    { status, headers: { 'Content-Type': 'application/json' } },
  );
}
function client(
  element: HTMLElement,
  fetcher: Fetcher,
  patch: Patcher = (from, to) => {
    from.innerHTML = to.innerHTML;
  },
): IslandClient {
  const value = mountClient(element, fetcher, patch);
  clients.push(value);
  return value;
}
afterEach(() => {
  clients.splice(0).forEach((value) => {
    destroyBindings(value.root);
    value.dispose();
  });
  document.body.innerHTML = '';
  vi.useRealTimers();
});

describe('explicit failure recovery', () => {
  it.each([
    [409, 'snapshot_expired'],
    [409, 'session_changed'],
    [403, 'csrf_failed'],
  ])(
    'offers reload after %s %s without replaying queued actions',
    async (status, code) => {
      const element = root();
      const reload = vi
        .spyOn(BrowserEffects.prototype, 'reload')
        .mockImplementation(() => {});
      vi.mocked(window.confirm).mockReturnValue(true);
      const fetcher = vi
        .fn<Fetcher>()
        .mockResolvedValue(
          new Response(
            JSON.stringify({ version: 1, error: { code, message: 'Expired' } }),
            { status, headers: { 'Content-Type': 'application/json' } },
          ),
        );
      const value = client(element, fetcher);
      edit(element, 'keep my work');
      const results = await Promise.all([
        value.bridge.call('save'),
        value.bridge.call('save'),
      ]);
      expect(results).toEqual([
        expect.objectContaining({
          ok: false,
          error: expect.objectContaining({ code }),
        }),
        expect.objectContaining({
          ok: false,
          error: expect.objectContaining({ code }),
        }),
      ]);
      expect(fetcher).toHaveBeenCalledOnce();
      expect(window.confirm).toHaveBeenCalledOnce();
      expect(reload).toHaveBeenCalledOnce();
      expect(value.bridge.paused).toBe(true);
      expect(JSON.parse(value.bridge.exportDraft())).toMatchObject({
        title: 'keep my work',
      });
    },
  );

  it.each(['root', 'wrapper', 'document', 'window'])(
    'allows %s listeners to replace the prompt without changing failure handling',
    async (target) => {
      const element = root();
      const wrapper = document.createElement('section');
      element.before(wrapper);
      wrapper.append(element);
      const fetcher = vi.fn<Fetcher>().mockResolvedValue(
        new Response(
          JSON.stringify({
            version: 1,
            error: { code: 'snapshot_expired', message: 'Expired' },
          }),
          { status: 409, headers: { 'Content-Type': 'application/json' } },
        ),
      );
      const value = client(element, fetcher);
      const listener = vi.fn((event: Event) => {
        expect(value.bridge.paused).toBe(true);
        expect(value.bridge.error?.code).toBe('snapshot_expired');
        expect(event.cancelable).toBe(true);
        event.preventDefault();
      });
      const destination =
        target === 'root'
          ? element
          : target === 'wrapper'
            ? wrapper
            : target === 'document'
              ? document
              : window;
      destination.addEventListener('interact:error', listener, { once: true });
      const result = await value.bridge.call('save');
      expect(result).toMatchObject({
        ok: false,
        error: { code: 'snapshot_expired' },
      });
      expect(listener).toHaveBeenCalledOnce();
      expect(window.confirm).not.toHaveBeenCalled();
      const other = client(
        root(),
        vi.fn<Fetcher>().mockResolvedValue(
          new Response(
            JSON.stringify({
              version: 1,
              error: { code: 'csrf_failed', message: 'Expired' },
            }),
            { status: 403, headers: { 'Content-Type': 'application/json' } },
          ),
        ),
      );
      await other.bridge.sync();
      expect(window.confirm).toHaveBeenCalledOnce();
    },
  );

  it('uses the original failure when a listener mutates its event detail', async () => {
    const element = root();
    const value = client(
      element,
      vi.fn<Fetcher>().mockResolvedValue(
        new Response(
          JSON.stringify({
            version: 1,
            error: { code: 'snapshot_expired', message: 'Expired' },
          }),
          { status: 409, headers: { 'Content-Type': 'application/json' } },
        ),
      ),
    );
    element.addEventListener('interact:error', (event) => {
      if (event instanceof CustomEvent) event.detail.code = 'network_error';
    });
    await value.bridge.sync();
    expect(window.confirm).toHaveBeenCalledOnce();
    expect(value.bridge.error?.code).toBe('snapshot_expired');
  });

  it.each(['remove', 'dispose'])(
    'does not prompt after an error listener chooses to %s the island',
    async (operation) => {
      const element = root();
      const value = client(
        element,
        vi.fn<Fetcher>().mockResolvedValue(
          new Response(
            JSON.stringify({
              version: 1,
              error: { code: 'snapshot_expired', message: 'Expired' },
            }),
            { status: 409, headers: { 'Content-Type': 'application/json' } },
          ),
        ),
      );
      element.addEventListener('interact:error', () => {
        if (operation === 'remove') element.remove();
        else value.dispose();
      });
      await value.bridge.sync();
      expect(window.confirm).not.toHaveBeenCalled();
    },
  );

  it('cancels pending timers and actions, retains later drafts, and resumes on explicit submit', async () => {
    vi.useFakeTimers();
    const element = root();
    let fail: (reason: unknown) => void = () => {};
    const fetcher = vi
      .fn<Fetcher>()
      .mockReturnValueOnce(
        new Promise((_resolve, reject) => {
          fail = reject;
        }),
      )
      .mockResolvedValueOnce(success(element, 'latest', 422))
      .mockResolvedValue(success(element, 'automatic'));
    const value = client(element, fetcher);
    const listener = vi.fn();
    element.addEventListener('interact:error', listener);
    edit(element, 'sent');
    const first = value.bridge.call('save');
    const second = value.bridge.call('save');
    const rejected = [
      expect(first).resolves.toMatchObject({
        ok: false,
        error: { code: 'network_error' },
      }),
      expect(second).resolves.toMatchObject({
        ok: false,
        error: { code: 'network_error' },
      }),
    ];
    edit(element, 'later');
    fail(new TypeError('private'));
    await Promise.all(rejected);
    expect(value.bridge.paused).toBe(true);
    expect(value.bridge.error).toMatchObject({
      code: 'network_error',
      status: null,
      recovery: 'retry',
    });
    edit(element, 'latest');
    input(element).removeAttribute('x-interact:model.live.debounce.30ms');
    input(element).setAttribute('x-interact:model.blur', 'title');
    refreshBindings(element);
    input(element).dispatchEvent(new Event('focusout', { bubbles: true }));
    input(element).removeAttribute('x-interact:model.blur');
    input(element).setAttribute('x-interact:model.change', 'title');
    refreshBindings(element);
    input(element).dispatchEvent(new Event('change', { bubbles: true }));
    await vi.runAllTimersAsync();
    expect(fetcher).toHaveBeenCalledOnce();
    expect(value.bridge.state.title).toBe('latest');
    expect(input(element).value).toBe('latest');
    expect(listener).toHaveBeenCalledOnce();
    const event: unknown = listener.mock.calls[0]?.[0];
    expect(event).toBeInstanceOf(CustomEvent);
    if (!(event instanceof CustomEvent)) throw new Error('Missing event');
    expect(event.detail).toEqual(value.bridge.error);
    const form = element.querySelector('form');
    if (!(form instanceof HTMLFormElement)) throw new Error('Missing form');
    form.dispatchEvent(
      new Event('submit', { bubbles: true, cancelable: true }),
    );
    await vi.runAllTimersAsync();
    expect(fetcher).toHaveBeenCalledTimes(2);
    expect(requestBody(fetcher.mock.calls[1]?.[1])).toMatchObject({
      snapshot: 'original',
      updates: { title: 'latest' },
      action: 'save',
    });
    expect(value.bridge.error).toBeNull();
    expect(value.bridge.paused).toBe(false);
    expect(value.bridge.errors).toEqual({ title: ['Required'] });
    edit(element, 'automatic');
    await vi.runAllTimersAsync();
    expect(fetcher).toHaveBeenCalledTimes(3);
  });
  it.each([
    [409, 'snapshot_expired'],
    [409, 'session_changed'],
    [409, 'invalid_snapshot'],
    [401, 'unauthenticated'],
    [403, 'csrf_failed'],
    [403, 'forbidden'],
    [404, 'http_error'],
  ])('blocks terminal recovery for %s %s', async (status, code) => {
    const element = root();
    const fetcher = vi.fn<Fetcher>().mockResolvedValue(
      new Response(
        JSON.stringify({
          version: 1,
          error: { code, message: 'Safe feedback' },
        }),
        { status, headers: { 'Content-Type': 'application/json' } },
      ),
    );
    const value = client(element, fetcher);
    const event = vi.fn();
    element.addEventListener('interact:error', event);
    await expect(value.bridge.sync()).resolves.toMatchObject({
      error: { code },
    });
    edit(element, 'retained');
    await expect(value.bridge.call('save')).resolves.toMatchObject({
      error: { code },
    });
    expect(fetcher).toHaveBeenCalledOnce();
    expect(event).toHaveBeenCalledOnce();
    expect(value.bridge.state.title).toBe('retained');
    expect(value.bridge.paused).toBe(true);
  });
  it('allows an error listener to start fresh work after cancelling the old queue', async () => {
    const element = root();
    let fail: (reason: unknown) => void = () => {};
    const fetcher = vi
      .fn<Fetcher>()
      .mockReturnValueOnce(
        new Promise((_resolve, reject) => {
          fail = reject;
        }),
      )
      .mockResolvedValue(success(element, 'fresh'));
    const value = client(element, fetcher);
    let resumed: Promise<RequestResult> | undefined;
    element.addEventListener(
      'interact:error',
      () => {
        edit(element, 'fresh');
        resumed = value.bridge.sync();
      },
      { once: true },
    );
    const first = value.bridge.call('save');
    const second = value.bridge.call('save');
    const rejected = [
      expect(first).resolves.toMatchObject({
        ok: false,
        error: { code: 'network_error' },
      }),
      expect(second).resolves.toMatchObject({
        ok: false,
        error: { code: 'network_error' },
      }),
    ];
    fail(new Error('connection'));
    await Promise.all(rejected);
    await resumed;
    expect(fetcher).toHaveBeenCalledTimes(2);
    expect(requestBody(fetcher.mock.calls[1]?.[1])).toMatchObject({
      action: null,
      updates: { title: 'fresh' },
    });
    expect(value.bridge.paused).toBe(false);
  });
  it('exports all writable drafts including clean values, preserving scalar distinctions', async () => {
    const element = root();
    const value = client(
      element,
      vi.fn<Fetcher>().mockResolvedValue(success(element, 'accepted')),
    );
    edit(element, 'accepted');
    await value.bridge.sync();
    expect(value.bridge.dirty).toBe(false);
    const exported: unknown = JSON.parse(value.bridge.exportDraft());
    expect(exported).toEqual({
      title: 'accepted',
      form: { enabled: false, zero: 0, empty: '', value: null },
    });
    expect(value.bridge.exportDraft()).not.toContain('locked');
    expect(value.bridge.exportDraft()).not.toContain('snapshot');
    edit(element, 'new draft');
    expect(value.bridge.exportDraft()).toContain('new draft');
  });
  it('restores accepted state and drafts when morphing fails, without applying events', async () => {
    const element = root();
    const patch = vi.fn<Patcher>().mockImplementation(() => {
      throw new Error('private');
    });
    const value = client(
      element,
      vi.fn<Fetcher>().mockResolvedValue(success(element, 'server')),
      patch,
    );
    edit(element, 'draft');
    await expect(value.bridge.sync()).resolves.toMatchObject({
      error: { code: 'client_error', status: 200 },
    });
    expect(value.bridge.state.title).toBe('draft');
    expect(value.bridge.exportDraft()).toContain('draft');
    expect(element.dataset.interactSnapshot).toBe('original');
    expect(value.bridge.dirty).toBe(true);
  });
  it('reports invalid control capture without sending and stays blocked when feedback is dismissed', async () => {
    const element = root();
    const fetcher = vi.fn<Fetcher>();
    const value = client(element, fetcher);
    input(element).type = 'file';
    await expect(value.bridge.sync()).resolves.toMatchObject({
      error: { code: 'client_error', status: null },
    });
    expect(fetcher).not.toHaveBeenCalled();
    value.bridge.error = null;
    await expect(value.bridge.call('save')).resolves.toMatchObject({
      error: { code: 'client_error' },
    });
  });
  it('suppresses a disposal abort without exposing a user-facing failure', async () => {
    const element = root();
    const fetcher = vi.fn<Fetcher>().mockImplementation(
      (_url, init) =>
        new Promise((_resolve, reject) => {
          init.signal?.addEventListener('abort', () =>
            reject(new DOMException('removed', 'AbortError')),
          );
        }),
    );
    const value = client(element, fetcher);
    const listener = vi.fn();
    element.addEventListener('interact:error', listener);
    const pending = value.bridge.sync();
    const rejected = expect(pending).resolves.toMatchObject({
      ok: false,
      error: { code: 'cancelled' },
    });
    value.dispose();
    await rejected;
    expect(listener).not.toHaveBeenCalled();
    expect(value.bridge.error).toBeNull();
    expect(value.bridge.busy).toBe(false);
  });
});
