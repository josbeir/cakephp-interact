import { afterEach, describe, expect, it, vi } from 'vite-plus/test';
import {
  mountClient,
  destroyBindings,
  refreshBindings,
} from '../support/mount';
import type { IslandClient } from '../../../resources/ts/client';
import type {
  Fetcher,
  IslandResponse,
  State,
  Writable,
} from '../../../resources/ts/types';

const fields: Writable = {
  title: { type: 'string', nullable: false },
  count: { type: 'int', nullable: false },
  amount: { type: 'float', nullable: true },
  enabled: { type: 'bool', nullable: false },
  tags: { type: 'array', nullable: false },
  choice: { type: 'string', nullable: false },
  form: { type: 'array', nullable: false },
};
const initial: State = {
  title: 'old',
  count: 0,
  amount: null,
  enabled: false,
  tags: [],
  choice: 'a',
  form: { name: '' },
  locked: 'server',
};
const clients: IslandClient[] = [];

/**
 * Read a captured request body only after checking the transport sent JSON text.
 */
function requestBody(options: RequestInit | undefined): unknown {
  const body = options?.body;
  if (typeof body !== 'string')
    throw new Error('Expected a JSON request body.');
  return JSON.parse(body);
}

function element(content = ''): HTMLDivElement {
  const root = document.createElement('div');
  root.dataset.interactId = 'test-id';
  root.dataset.interactSnapshot = 'initial-signature';
  root.dataset.interactState = JSON.stringify(initial);
  root.dataset.interactWritable = JSON.stringify(fields);
  root.dataset.interactEndpoint = '/_interact/update';
  root.dataset.interactCsrf = 'csrf';
  root.innerHTML = content;
  document.body.append(root);
  return root;
}
function payload(
  root: HTMLElement,
  next: State = initial,
  changes: Partial<IslandResponse> = {},
): IslandResponse {
  return {
    version: 1,
    id: 'test-id',
    snapshot: 'new-signature',
    html: root.outerHTML,
    state: structuredClone(next),
    writable: fields,
    errors: {},
    events: [],
    ...changes,
  };
}
function result(
  value: unknown,
  status = 200,
  type = 'application/json',
): Response {
  return new Response(JSON.stringify(value), {
    status,
    headers: { 'Content-Type': type },
  });
}
function client(root: HTMLElement, fetcher: Fetcher): IslandClient {
  const value = mountClient(root, fetcher);
  clients.push(value);
  return value;
}
function control<T extends HTMLElement>(
  root: HTMLElement,
  selector: string,
  expected: new (...args: never[]) => T,
): T {
  const found = root.querySelector(selector);
  if (!(found instanceof expected)) throw new Error('Missing test control');
  return found;
}
afterEach(() => {
  clients.splice(0).forEach((value) => {
    destroyBindings(value.root);
    value.dispose();
  });
  document.body.innerHTML = '';
  vi.useRealTimers();
});

describe('island lifecycle and transport', () => {
  it('ignores a response body that finishes parsing after disposal', async () => {
    const root = element();
    let finish: (payload: unknown) => void = () => {};
    const body = new Promise<unknown>((resolve) => {
      finish = resolve;
    });
    const reply = result(payload(root));
    const parse = vi.spyOn(reply, 'json').mockReturnValue(body);
    const value = client(root, vi.fn<Fetcher>().mockResolvedValue(reply));
    const pending = value.bridge.sync();
    await vi.waitFor(() => expect(parse).toHaveBeenCalledOnce());
    value.dispose();
    finish(payload(root, { ...initial, title: 'late body' }));
    await pending;
    expect(value.bridge.state.title).toBe('old');
  });
  it('rejects non-JSON drafts without hanging the queue or sending a request', async () => {
    const root = element();
    const fetcher = vi.fn<Fetcher>();
    const value = client(root, fetcher);
    value.bridge.state.amount = Number.NaN;
    await expect(value.bridge.sync()).resolves.toMatchObject({
      ok: false,
      error: { code: 'client_error' },
    });
    expect(fetcher).not.toHaveBeenCalled();
    expect(value.bridge.busy).toBe(false);
    expect(value.bridge.error).toMatchObject({
      code: 'client_error',
      status: null,
      recovery: 'reload',
    });
  });
  it('validates initial metadata, identity, and same-origin endpoints', () => {
    const root = element();
    root.dataset.interactState = '[]';
    expect(() => client(root, vi.fn())).toThrow('state');
    root.dataset.interactState = '{}';
    root.dataset.interactId = '';
    expect(() => client(root, vi.fn())).toThrow('identity');
    root.dataset.interactId = 'test-id';
    root.dataset.interactEndpoint = 'https://elsewhere.test/update';
    expect(() => client(root, vi.fn())).toThrow('same-origin');
  });
  it('initializes escaped field feedback before Alpine bindings and clears it after success', async () => {
    const root = element(
      '<p x-text="$server.errors.title ? $server.errors.title[0] : \'\'"></p>',
    );
    root.dataset.interactErrors = JSON.stringify({
      title: ['Required <strong>title</strong>'],
    });
    const value = client(
      root,
      vi.fn<Fetcher>().mockResolvedValue(result(payload(root))),
    );
    expect(value.bridge.errors).toEqual({
      title: ['Required <strong>title</strong>'],
    });
    expect(root.querySelector('p')?.textContent).toBe(
      'Required <strong>title</strong>',
    );
    expect(root.querySelector('strong')).toBeNull();
    await value.bridge.call('clear');
    expect(value.bridge.errors).toEqual({});
    await vi.waitFor(() =>
      expect(root.querySelector('p')?.textContent).toBe(''),
    );
  });

  it.each([[], null, { title: 'Required' }, { title: [42] }])(
    'rejects malformed initial feedback %j',
    (errors) => {
      const root = element();
      root.dataset.interactErrors = JSON.stringify(errors);
      expect(() => client(root, vi.fn())).toThrow('initial validation errors');
    },
  );

  it('rejects field feedback on an unloaded placeholder', () => {
    const root = element();
    root.dataset.interactState = '{}';
    root.dataset.interactWritable = '{}';
    root.dataset.interactLoading = 'manual';
    root.dataset.interactErrors = '{"title":["Required"]}';
    expect(() => client(root, vi.fn())).toThrow('initial island state');
  });

  it('flushes pending edits with explicit actions and applies success events', async () => {
    const root = element();
    const event = vi.fn();
    root.addEventListener('saved', event);
    const fetcher = vi
      .fn<Fetcher>()
      .mockResolvedValue(
        result(
          payload(
            root,
            { ...initial, title: 'new' },
            { events: [{ name: 'saved', detail: { id: 7 } }] },
          ),
        ),
      );
    const value = client(root, fetcher);
    value.bridge.state.title = 'new';
    expect(value.bridge.dirty).toBe(true);
    await value.bridge.call('save', 7);
    const options = fetcher.mock.calls[0]?.[1];
    expect(options?.credentials).toBe('same-origin');
    expect(options?.redirect).toBe('manual');
    expect(options?.headers).toMatchObject({
      Interact: 'true',
      'X-CSRF-Token': 'csrf',
    });
    expect(requestBody(options)).toMatchObject({
      version: 1,
      snapshot: 'initial-signature',
      updates: { title: 'new' },
      action: 'save',
      args: [7],
    });
    expect(value.bridge.dirty).toBe(false);
    expect(event).toHaveBeenCalledOnce();
    expect(value.bridge.busy).toBe(false);
  });
  it('keeps newer typing and serializes queued actions against the latest snapshot', async () => {
    const root = element('<input x-interact:model="title" value="old">');
    let finish: (response: Response) => void = () => {};
    const pending = new Promise<Response>((resolve) => {
      finish = resolve;
    });
    const fetcher = vi
      .fn<Fetcher>()
      .mockReturnValueOnce(pending)
      .mockResolvedValue(result(payload(root, { ...initial, title: 'newer' })));
    const value = client(root, fetcher);
    const input = control(root, 'input', HTMLInputElement);
    input.value = 'sent';
    input.dispatchEvent(new Event('input', { bubbles: true }));
    const first = value.bridge.call('save');
    input.value = 'newer';
    input.dispatchEvent(new Event('input', { bubbles: true }));
    const second = value.bridge.call('save');
    expect(fetcher).toHaveBeenCalledOnce();
    finish(result(payload(root, { ...initial, title: 'sent' })));
    await first;
    expect(value.bridge.state.title).toBe('newer');
    expect(control(root, 'input', HTMLInputElement).value).toBe('newer');
    await second;
    expect(requestBody(fetcher.mock.calls[1]?.[1])).toMatchObject({
      snapshot: 'new-signature',
      updates: { title: 'newer' },
    });
  });
  it('keeps a server-added form row while another field is edited during the request', async () => {
    const root = element('<input x-interact:model="form.name">');
    let finish: (response: Response) => void = () => {};
    const fetcher = vi.fn<Fetcher>().mockReturnValue(
      new Promise<Response>((resolve) => {
        finish = resolve;
      }),
    );
    const value = client(root, fetcher);
    const pending = value.bridge.call('addComment');
    const input = control(root, 'input', HTMLInputElement);
    input.value = 'newer name';
    input.dispatchEvent(new Event('input', { bubbles: true }));
    finish(
      result(
        payload(root, {
          ...initial,
          form: { name: '', comments: [{ body: '' }] },
        }),
      ),
    );
    await expect(pending).resolves.toEqual({ ok: true });
    expect(value.bridge.state.form).toEqual({
      name: 'newer name',
      comments: [{ body: '' }],
    });
    expect(control(root, 'input', HTMLInputElement).value).toBe('newer name');
  });

  it('keeps queued arguments and their activity targets detached from application mutations', async () => {
    const root = element();
    let finish: (response: Response) => void = () => {};
    const fetcher = vi
      .fn<Fetcher>()
      .mockReturnValueOnce(
        new Promise<Response>((resolve) => {
          finish = resolve;
        }),
      )
      .mockResolvedValue(result(payload(root)));
    const value = client(root, fetcher);
    const first = value.bridge.sync();
    const argument = { nested: { value: 'original' } };
    const second = value.bridge.call('save', argument);
    argument.nested.value = 'changed';
    expect(
      value.bridge.pending('save', { nested: { value: 'original' } }),
    ).toBe(true);
    expect(value.bridge.pending('save', argument)).toBe(false);
    finish(result(payload(root)));
    await Promise.all([first, second]);
    expect(requestBody(fetcher.mock.calls[1]?.[1])).toMatchObject({
      args: [{ nested: { value: 'original' } }],
    });
  });
  it('coalesces queued synchronization and accepts renderable validation responses', async () => {
    const root = element();
    let finish: (response: Response) => void = () => {};
    const fetcher = vi
      .fn<Fetcher>()
      .mockReturnValueOnce(
        new Promise<Response>((resolve) => {
          finish = resolve;
        }),
      )
      .mockResolvedValue(
        result(
          payload(root, initial, { errors: { title: ['Required'] } }),
          422,
        ),
      );
    const value = client(root, fetcher);
    const first = value.bridge.sync();
    const second = value.bridge.sync();
    const third = value.bridge.sync();
    finish(result(payload(root)));
    await Promise.all([first, second, third]);
    expect(fetcher).toHaveBeenCalledTimes(2);
    expect(value.bridge.errors).toEqual({ title: ['Required'] });
  });
  it.each([
    ['status', 403, 'application/json', {}],
    ['type', 200, 'text/html', {}],
    ['envelope', 200, 'application/json', {}],
  ])(
    'rejects invalid %s without retrying',
    async (_reason, status, type, data) => {
      const root = element();
      const fetcher = vi
        .fn<Fetcher>()
        .mockResolvedValue(result(data, status, type));
      const value = client(root, fetcher);
      const error = vi.fn();
      root.addEventListener('interact:error', error);
      await expect(value.bridge.call('save')).resolves.toMatchObject({
        ok: false,
      });
      expect(fetcher).toHaveBeenCalledOnce();
      expect(error).toHaveBeenCalledOnce();
      expect(value.bridge.error).not.toBeNull();
    },
  );
  it.each([
    '<span></span>',
    '<div data-interact-id="wrong"></div>',
    '<div></div><div></div>',
  ])('rejects mismatched markup %s', async (html) => {
    const root = element();
    const value = client(
      root,
      vi
        .fn<Fetcher>()
        .mockResolvedValue(result(payload(root, initial, { html }))),
    );
    await expect(value.bridge.sync()).resolves.toMatchObject({
      ok: false,
      error: { code: 'invalid_response', status: 200 },
    });
  });
  it('fails pending work after a transport error without replaying actions', async () => {
    const root = element();
    let reject: (error: unknown) => void = () => {};
    const fetcher = vi.fn<Fetcher>().mockReturnValue(
      new Promise((_resolve, fail) => {
        reject = fail;
      }),
    );
    const value = client(root, fetcher);
    const first = value.bridge.call('save');
    const second = value.bridge.call('save');
    const firstFailure = expect(first).resolves.toMatchObject({
      ok: false,
      error: { code: 'network_error', status: null },
    });
    const secondFailure = expect(second).resolves.toMatchObject({
      ok: false,
      error: { code: 'network_error', status: null },
    });
    reject('network lost');
    await Promise.all([firstFailure, secondFailure]);
    expect(fetcher).toHaveBeenCalledOnce();
  });
  it('disposes timers and queued work and ignores late responses', async () => {
    vi.useFakeTimers();
    const root = element('<input x-interact:model.live="title">');
    let finish: (response: Response) => void = () => {};
    const fetcher = vi.fn<Fetcher>().mockReturnValue(
      new Promise<Response>((resolve) => {
        finish = resolve;
      }),
    );
    const value = client(root, fetcher);
    control(root, 'input', HTMLInputElement).dispatchEvent(
      new Event('input', { bubbles: true }),
    );
    const first = value.bridge.call('save');
    const second = value.bridge.call('save');
    const rejection = expect(second).resolves.toMatchObject({
      ok: false,
      error: { code: 'cancelled' },
    });
    value.dispose();
    finish(result(payload(root, { ...initial, title: 'late' })));
    await Promise.all([first, rejection]);
    expect(value.bridge.state.title).not.toBe('late');
    await expect(value.bridge.sync()).resolves.toMatchObject({
      ok: false,
      error: { code: 'cancelled' },
    });
    await vi.runAllTimersAsync();
    expect(fetcher).toHaveBeenCalledOnce();
  });
});

describe('declarative controls', () => {
  it('keeps edits local by default and flushes controls before submit', async () => {
    const root = element(
      '<form x-interact:submit="$server.call(\'save\')"><input x-interact:model="title" value="old"><button><span>Save</span></button></form>',
    );
    const fetcher = vi
      .fn<Fetcher>()
      .mockResolvedValue(result(payload(root, { ...initial, title: 'draft' })));
    const value = client(root, fetcher);
    const input = control(root, 'input', HTMLInputElement);
    input.value = 'draft';
    input.dispatchEvent(new Event('input', { bubbles: true }));
    expect(value.bridge.state.title).toBe('draft');
    expect(fetcher).not.toHaveBeenCalled();
    control(root, 'form', HTMLFormElement).dispatchEvent(
      new Event('submit', { bubbles: true, cancelable: true }),
    );
    await vi.waitFor(() => expect(fetcher).toHaveBeenCalledOnce());
    expect(requestBody(fetcher.mock.calls[0]?.[1])).toMatchObject({
      updates: { title: 'draft' },
    });
  });
  it('locks a native form while queued, keeps later drafts, and unlocks validation responses', async () => {
    const root = element(
      '<form id="native-form" x-interact:submit="$server.call(\'save\')"><input x-interact:model="title" value="old"><button>Save</button></form>',
    );
    const html = root.outerHTML;
    let finish: (response: Response) => void = () => {};
    const fetcher = vi
      .fn<Fetcher>()
      .mockReturnValueOnce(
        new Promise<Response>((resolve) => {
          finish = resolve;
        }),
      )
      .mockResolvedValueOnce(
        result(
          {
            ...payload(
              root,
              { ...initial, title: 'later' },
              { errors: { title: ['Invalid'] } },
            ),
            html,
          },
          422,
        ),
      );
    const value = client(root, fetcher);
    const sync = value.bridge.sync();
    const form = control(root, 'form', HTMLFormElement);
    const button = control(root, 'button', HTMLButtonElement);
    const input = control(root, 'input', HTMLInputElement);
    form.requestSubmit();
    form.requestSubmit();
    expect(button.disabled).toBe(true);
    expect(input.disabled).toBe(false);
    expect(value.bridge.pending('save')).toBe(true);
    expect(fetcher).toHaveBeenCalledOnce();
    input.value = 'later';
    input.dispatchEvent(new Event('input', { bubbles: true }));
    finish(result({ ...payload(root), html }));
    await sync;
    await vi.waitFor(() => expect(value.bridge.pending()).toBe(false));
    expect(fetcher).toHaveBeenCalledTimes(2);
    expect(requestBody(fetcher.mock.calls[1]?.[1])).toMatchObject({
      action: 'save',
      updates: { title: 'later' },
    });
    expect(control(root, 'button', HTMLButtonElement).disabled).toBe(false);
    expect(value.bridge.errors).toEqual({ title: ['Invalid'] });
  });
  it('handles explicit clicks, blur, change, and input debounce without duplicate requests', async () => {
    vi.useFakeTimers();
    const root = element(
      '<input x-interact:model.live.debounce.30ms="title"><button type="button" @click="$server.call(\'save\')"><span>Save</span></button>',
    );
    const fetcher = vi
      .fn<Fetcher>()
      .mockImplementation(async () => result(payload(root)));
    client(root, fetcher);
    let input = control(root, 'input', HTMLInputElement);
    input.value = 'a';
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.value = 'ab';
    input.dispatchEvent(new Event('input', { bubbles: true }));
    await vi.advanceTimersByTimeAsync(29);
    expect(fetcher).not.toHaveBeenCalled();
    await vi.advanceTimersByTimeAsync(1);
    expect(fetcher).toHaveBeenCalledOnce();
    input = control(root, 'input', HTMLInputElement);
    input.removeAttribute('x-interact:model.live.debounce.30ms');
    input.setAttribute('x-interact:model.blur', 'title');
    refreshBindings(root);
    input.dispatchEvent(new Event('focusout', { bubbles: true }));
    await vi.runAllTimersAsync();
    expect(fetcher).toHaveBeenCalledTimes(2);
    input = control(root, 'input', HTMLInputElement);
    input.removeAttribute('x-interact:model.blur');
    input.setAttribute('x-interact:model.change', 'title');
    refreshBindings(root);
    input.dispatchEvent(new Event('change', { bubbles: true }));
    await vi.runAllTimersAsync();
    expect(fetcher).toHaveBeenCalledTimes(3);
    control(root, 'span', HTMLSpanElement).click();
    await vi.runAllTimersAsync();
    expect(fetcher).toHaveBeenCalledTimes(4);
  });
  it.each(['compositionstart', 'composing input'])(
    'cancels an earlier live edit on %s and waits for committed IME text',
    async (start) => {
      vi.useFakeTimers();
      const root = element(
        '<input x-interact:model.live.debounce.30ms="title">',
      );
      const fetcher = vi
        .fn<Fetcher>()
        .mockImplementation(async () => result(payload(root)));
      const value = client(root, fetcher);
      const input = control(root, 'input', HTMLInputElement);
      input.value = 'before';
      input.dispatchEvent(new InputEvent('input', { bubbles: true }));
      await vi.advanceTimersByTimeAsync(10);
      if (start === 'compositionstart')
        input.dispatchEvent(
          new CompositionEvent('compositionstart', { bubbles: true }),
        );
      else {
        input.value = 'intermediate';
        input.dispatchEvent(
          new InputEvent('input', { bubbles: true, isComposing: true }),
        );
      }
      await vi.advanceTimersByTimeAsync(30);
      expect(fetcher).not.toHaveBeenCalled();
      input.value = '編集中';
      input.dispatchEvent(
        new InputEvent('input', { bubbles: true, isComposing: true }),
      );
      expect(value.bridge.state.title).toBe('編集中');
      await vi.advanceTimersByTimeAsync(50);
      expect(fetcher).not.toHaveBeenCalled();
      input.value = '編集済み';
      input.dispatchEvent(
        new CompositionEvent('compositionend', { bubbles: true }),
      );
      // Browsers may also emit a final non-composing input after compositionend.
      input.dispatchEvent(new InputEvent('input', { bubbles: true }));
      await vi.advanceTimersByTimeAsync(29);
      expect(fetcher).not.toHaveBeenCalled();
      await vi.advanceTimersByTimeAsync(1);
      expect(fetcher).toHaveBeenCalledOnce();
      expect(requestBody(fetcher.mock.calls[0]?.[1])).toMatchObject({
        updates: { title: '編集済み' },
      });
    },
  );

  it('supports checkboxes, radio groups, selections, numeric values, and nested state', async () => {
    const root = element(
      '<input type="checkbox" name="enabled" x-interact:model="enabled"><input type="checkbox" value="one" x-interact:model="tags"><input type="radio" value="b" x-interact:model="choice"><select multiple x-interact:model="tags"><option value="one">One</option><option value="two">Two</option></select><input name="count" x-interact:model="count"><input name="amount" x-interact:model="amount"><textarea x-interact:model="form.name"></textarea>',
    );
    const value = client(
      root,
      vi.fn<Fetcher>().mockResolvedValue(result(payload(root))),
    );
    const enabled = control(root, '[name="enabled"]', HTMLInputElement);
    enabled.checked = true;
    enabled.dispatchEvent(new Event('change', { bubbles: true }));
    expect(value.bridge.state.enabled).toBe(true);
    const check = control(root, 'input[value="one"]', HTMLInputElement);
    check.checked = true;
    check.dispatchEvent(new Event('change', { bubbles: true }));
    expect(value.bridge.state.tags).toEqual(['one']);
    check.checked = false;
    check.dispatchEvent(new Event('change', { bubbles: true }));
    expect(value.bridge.state.tags).toEqual([]);
    const radio = control(root, 'input[type="radio"]', HTMLInputElement);
    radio.dispatchEvent(new Event('change', { bubbles: true }));
    expect(value.bridge.state.choice).toBe('a');
    radio.checked = true;
    radio.dispatchEvent(new Event('change', { bubbles: true }));
    expect(value.bridge.state.choice).toBe('b');
    const select = control(root, 'select', HTMLSelectElement);
    const selected = select.options[1];
    if (!selected) throw new Error('Missing select option.');
    selected.selected = true;
    select.dispatchEvent(new Event('change', { bubbles: true }));
    expect(value.bridge.state.tags).toEqual(['two']);
    const count = control(root, '[name="count"]', HTMLInputElement);
    count.value = '0';
    count.dispatchEvent(new Event('input', { bubbles: true }));
    expect(value.bridge.state.count).toBe(0);
    const amount = control(root, '[name="amount"]', HTMLInputElement);
    amount.value = '';
    amount.dispatchEvent(new Event('input', { bubbles: true }));
    expect(value.bridge.state.amount).toBeNull();
    const text = control(root, 'textarea', HTMLTextAreaElement);
    text.value = 'nested';
    text.dispatchEvent(new Event('input', { bubbles: true }));
    expect(value.bridge.state.form).toEqual({ name: 'nested' });
    await value.bridge.sync();
    expect(control(root, '[name="count"]', HTMLInputElement).value).toBe('0');
    expect(control(root, '[name="amount"]', HTMLInputElement).value).toBe('');
  });
  it('ignores child islands, unrelated forms, disabled controls and IME intermediate input', async () => {
    vi.useFakeTimers();
    const root = element(
      '<div x-ignore data-interact-id="child"><input x-interact:model="title" value="child"><button @click="$server.call(\'save\')">Child</button></div><input disabled x-interact:model="title"><form></form><input x-interact:model.live="title">',
    );
    const fetcher = vi.fn<Fetcher>();
    const value = client(root, fetcher);
    control(root, 'div input', HTMLInputElement).dispatchEvent(
      new Event('input', { bubbles: true }),
    );
    control(root, 'input[disabled]', HTMLInputElement).dispatchEvent(
      new Event('change', { bubbles: true }),
    );
    control(root, 'form', HTMLFormElement).dispatchEvent(
      new Event('submit', { bubbles: true }),
    );
    const input = control(root, ':scope > input:last-child', HTMLInputElement);
    input.value = 'draft';
    input.dispatchEvent(
      new InputEvent('input', { bubbles: true, isComposing: true }),
    );
    await vi.runAllTimersAsync();
    expect(fetcher).not.toHaveBeenCalled();
    expect(value.bridge.state.title).toBe('draft');
  });
});
