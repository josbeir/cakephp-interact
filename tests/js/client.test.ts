import { afterEach, describe, expect, it, vi } from 'vitest';
import { IslandClient } from '../../resources/ts/client';
import type {
  Fetcher,
  IslandResponse,
  State,
  Writable,
} from '../../resources/ts/types';

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
  const value = new IslandClient(
    root,
    (target, replacement) => {
      target.innerHTML = replacement.innerHTML;
    },
    (value) => value,
    fetcher,
  );
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
  clients.splice(0).forEach((value) => value.dispose());
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
    expect(options?.headers).toMatchObject({ 'X-CSRF-Token': 'csrf' });
    expect(JSON.parse(String(options?.body))).toMatchObject({
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
    const root = element('<input data-interact-model="title" value="old">');
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
    expect(JSON.parse(String(fetcher.mock.calls[1]?.[1].body))).toMatchObject({
      snapshot: 'new-signature',
      updates: { title: 'newer' },
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
    const root = element(
      '<input data-interact-model="title" data-interact-update="input">',
    );
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
      '<form data-interact-submit="save"><input data-interact-model="title" value="old"><button><span>Save</span></button></form>',
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
    expect(
      JSON.parse(String(fetcher.mock.calls[0]?.[1].body)).updates.title,
    ).toBe('draft');
  });
  it('handles explicit clicks, blur, change, and input debounce without duplicate requests', async () => {
    vi.useFakeTimers();
    const root = element(
      '<input data-interact-model="title" data-interact-update="input" data-interact-debounce="30"><button type="button" data-interact-click="save"><span>Save</span></button>',
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
    input.dataset.interactUpdate = 'blur';
    input.dispatchEvent(new Event('focusout', { bubbles: true }));
    await vi.runAllTimersAsync();
    expect(fetcher).toHaveBeenCalledTimes(2);
    input = control(root, 'input', HTMLInputElement);
    input.dataset.interactUpdate = 'change';
    input.dispatchEvent(new Event('change', { bubbles: true }));
    await vi.runAllTimersAsync();
    expect(fetcher).toHaveBeenCalledTimes(3);
    control(root, 'span', HTMLSpanElement).click();
    await vi.runAllTimersAsync();
    expect(fetcher).toHaveBeenCalledTimes(4);
  });
  it('supports checkboxes, radio groups, selections, numeric values, and nested state', async () => {
    const root = element(
      '<input type="checkbox" data-interact-model="enabled"><input type="checkbox" value="one" data-interact-model="tags"><input type="radio" value="b" data-interact-model="choice"><select multiple data-interact-model="tags"><option value="one">One</option><option value="two">Two</option></select><input data-interact-model="count"><input data-interact-model="amount"><textarea data-interact-model="form.name"></textarea>',
    );
    const value = client(
      root,
      vi.fn<Fetcher>().mockResolvedValue(result(payload(root))),
    );
    const enabled = control(
      root,
      '[data-interact-model="enabled"]',
      HTMLInputElement,
    );
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
    select.options[1]?.setAttribute('selected', '');
    select.dispatchEvent(new Event('change', { bubbles: true }));
    expect(value.bridge.state.tags).toEqual(['two']);
    const count = control(
      root,
      '[data-interact-model="count"]',
      HTMLInputElement,
    );
    count.value = '0';
    count.dispatchEvent(new Event('input', { bubbles: true }));
    expect(value.bridge.state.count).toBe(0);
    const amount = control(
      root,
      '[data-interact-model="amount"]',
      HTMLInputElement,
    );
    amount.value = '';
    amount.dispatchEvent(new Event('input', { bubbles: true }));
    expect(value.bridge.state.amount).toBeNull();
    const text = control(root, 'textarea', HTMLTextAreaElement);
    text.value = 'nested';
    text.dispatchEvent(new Event('input', { bubbles: true }));
    expect(value.bridge.state.form).toEqual({ name: 'nested' });
    await value.bridge.sync();
    expect(
      control(root, '[data-interact-model="count"]', HTMLInputElement).value,
    ).toBe('0');
    expect(
      control(root, '[data-interact-model="amount"]', HTMLInputElement).value,
    ).toBe('');
  });
  it('ignores child islands, unrelated forms, disabled controls and IME intermediate input', async () => {
    vi.useFakeTimers();
    const root = element(
      '<div data-interact-id="child"><input data-interact-model="title" value="child"><button data-interact-click="save">Child</button></div><input disabled data-interact-model="title"><form></form><input data-interact-model="title" data-interact-update="input">',
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
    const input = control(
      root,
      'input[data-interact-update]',
      HTMLInputElement,
    );
    input.value = 'draft';
    input.dispatchEvent(
      new InputEvent('input', { bubbles: true, isComposing: true }),
    );
    await vi.runAllTimersAsync();
    expect(fetcher).not.toHaveBeenCalled();
    expect(value.bridge.state.title).toBe('draft');
  });
});
