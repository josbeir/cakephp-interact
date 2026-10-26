import { afterEach, beforeAll, describe, expect, it, vi } from 'vite-plus/test';
import Alpine from '@alpinejs/csp';
import install from '../../../resources/ts/index';
import type { IslandBridge, State } from '../../../resources/ts/types';

beforeAll(() => {
  install(Alpine);
  Alpine.start();
  Alpine.stopObservingMutations();
});

function mount(
  content: string,
  state: State = { title: 'original' },
): HTMLElement {
  Alpine.stopObservingMutations();
  install(Alpine);
  window.Alpine = Alpine;
  Alpine.data('testWidget', () => ({ value: '' }));
  const root = document.createElement('section');
  root.setAttribute('x-data', 'interact');
  Object.assign(root.dataset, {
    interactId: 'widgets',
    interactSnapshot: 'signed',
    interactEndpoint: '/_interact/update',
    interactState: JSON.stringify(state),
    interactWritable: JSON.stringify(
      Object.fromEntries(
        Object.keys(state).map((name) => [
          name,
          {
            type:
              typeof state[name] === 'number'
                ? 'int'
                : typeof state[name] === 'boolean'
                  ? 'bool'
                  : typeof state[name] === 'object' && state[name] !== null
                    ? 'array'
                    : 'string',
            nullable: true,
          },
        ]),
      ),
    ),
  });
  root.innerHTML = content;
  document.body.append(root);
  Alpine.initTree(root);
  Alpine.startObservingMutations();
  return root;
}
function wrapper(root: HTMLElement): HTMLElement {
  const element = root.querySelector<HTMLElement>('[x-modelable]');
  if (!element) throw new Error('Missing widget.');
  return element;
}
function bridge(root: HTMLElement): IslandBridge {
  return Alpine.evaluateRaw<IslandBridge>(root, '$server');
}
function edit(root: HTMLElement, value: unknown): void {
  Alpine.evaluateRaw(wrapper(root), 'value = __next', {
    scope: { __next: value },
  });
}
function value(root: HTMLElement): unknown {
  return Alpine.evaluateRaw<unknown>(wrapper(root), 'value');
}
function reply(
  root: HTMLElement,
  state: State,
  html = root.outerHTML,
): Response {
  return new Response(
    JSON.stringify({
      version: 1,
      id: 'widgets',
      snapshot: 'accepted',
      html,
      state,
      writable: JSON.parse(root.dataset.interactWritable ?? '{}'),
      errors: {},
      events: [],
    }),
    { headers: { 'Content-Type': 'application/json' } },
  );
}
function custom(modifiers = '', path = 'title'): string {
  return `<div id="widget" x-data="testWidget" x-modelable="value" x-interact:model${modifiers}="${path}"><input><button>Inside</button></div>`;
}
function requestBody(fetcher: ReturnType<typeof vi.fn>): unknown {
  const options: unknown = fetcher.mock.calls[0]?.[1];
  if (
    typeof options !== 'object' ||
    options === null ||
    !('body' in options) ||
    typeof options.body !== 'string'
  )
    throw new Error('Missing JSON request.');
  return JSON.parse(options.body);
}

afterEach(() => {
  Alpine.stopObservingMutations();
  for (const root of document.querySelectorAll<HTMLElement>(
    '[data-interact-id]',
  ))
    Alpine.destroyTree(root);
  document.body.innerHTML = '';
  delete window.Alpine;
  vi.unstubAllGlobals();
  vi.useRealTimers();
});

describe('widget DOM ownership', () => {
  it('supports inline CSP data expressions while preserving local values across server updates', async () => {
    const root = mount(
      `<div x-data="{ expanded: false, initialCount: $server.state.count }">
        <button type="button" @click="expanded = !expanded" :aria-expanded="expanded">Toggle details</button>
        <div x-show="expanded">
          <p id="initial" x-text="'Count at initialization: ' + initialCount"></p>
          <p id="current" x-text="'Current count doubled: ' + ($server.state.count * 2)"></p>
        </div>
        <button type="button" @click="$server.call('increment')">Add one</button>
      </div>`,
      { count: 2 },
    );
    const toggle = root.querySelector('button');
    expect(toggle?.getAttribute('aria-expanded')).toBe('false');
    expect(root.querySelector('#initial')?.textContent).toBe(
      'Count at initialization: 2',
    );
    expect(root.querySelector('#current')?.textContent).toBe(
      'Current count doubled: 4',
    );
    const fetcher = vi.fn().mockResolvedValue(reply(root, { count: 3 }));
    vi.stubGlobal('fetch', fetcher);
    toggle?.click();
    await vi.waitFor(() =>
      expect(toggle?.getAttribute('aria-expanded')).toBe('true'),
    );
    root.querySelectorAll('button')[1]?.click();
    await vi.waitFor(() =>
      expect(root.querySelector('#current')?.textContent).toBe(
        'Current count doubled: 6',
      ),
    );
    expect(fetcher).toHaveBeenCalledOnce();
    expect(requestBody(fetcher)).toMatchObject({
      action: 'increment',
      args: [],
    });
    expect(root.querySelector('#initial')?.textContent).toBe(
      'Count at initialization: 2',
    );
    expect(toggle?.getAttribute('aria-expanded')).toBe('true');
  });

  it('updates a widget from nested server data without sharing state or recreating it, and disposes it', async () => {
    let data: unknown;
    const initialized = vi.fn();
    const updated = vi.fn();
    const destroyed = vi.fn();
    Alpine.data('chartProbe', () => ({
      init() {
        initialized();
        data = structuredClone(Alpine.raw(bridge(this.$el).state.chartData));
        this.$watch('$server.state.chartData', (accepted: unknown) => {
          data = structuredClone(Alpine.raw(accepted));
          updated();
        });
      },
      destroy() {
        destroyed();
      },
    }));
    const root = mount(
      '<div x-data="chartProbe"><div x-interact:ignore><canvas data-instance="retained"></canvas></div></div>',
      { chartData: { labels: ['Monday'], datasets: [{ data: [12] }] } },
    );
    const canvas = root.querySelector('canvas');
    expect(data).toEqual({ labels: ['Monday'], datasets: [{ data: [12] }] });
    expect(data).not.toBe(bridge(root).state.chartData);
    // A library may mutate its own copy without creating island drafts.
    if (typeof data !== 'object' || data === null)
      throw new Error('Missing chart data');
    Reflect.set(data, 'libraryOnly', true);
    expect(bridge(root).state.chartData).not.toHaveProperty('libraryOnly');
    const next = {
      chartData: { labels: ['Monday'], datasets: [{ data: [13] }] },
    };
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue(reply(root, next)));
    await bridge(root).sync();
    await vi.waitFor(() => expect(updated).toHaveBeenCalledOnce());
    expect(data).toEqual(next.chartData);
    expect(root.querySelector('canvas')).toBe(canvas);
    expect(initialized).toHaveBeenCalledOnce();
    Alpine.destroyTree(root);
    root.remove();
    expect(destroyed).toHaveBeenCalledOnce();
  });

  it('protects SVG attributes and children for application-owned charts', async () => {
    const root = mount(
      '<svg id="chart" viewBox="0 0 10 10" x-interact:ignore><circle r="3" /></svg>',
    );
    const next = root.cloneNode(true);
    if (!(next instanceof HTMLElement)) throw new Error('Missing render.');
    next.innerHTML =
      '<svg id="chart" viewBox="0 0 20 20" x-interact:ignore><circle r="8" /></svg>';
    vi.stubGlobal(
      'fetch',
      vi
        .fn()
        .mockResolvedValue(reply(root, { title: 'original' }, next.outerHTML)),
    );
    await bridge(root).sync();
    expect(root.querySelector('svg')?.getAttribute('viewBox')).toBe(
      '0 0 10 10',
    );
    expect(root.querySelector('circle')?.getAttribute('r')).toBe('3');
  });
  it('keeps protected markup and attributes while PHP updates its surroundings and Alpine remains active', async () => {
    const root = mount(
      '<p id="normal">0</p><div id="protected" class="mint" x-interact:ignore x-data="{ local: 0 }"><p>0</p><button @click="local++">Local</button><span x-text="local"></span></div>',
    );
    const protectedNode = root.querySelector('#protected');
    const next = root.cloneNode(true);
    if (!(next instanceof HTMLElement)) throw new Error('Missing render.');
    next.innerHTML =
      '<p id="normal">3</p><div id="protected" class="amber" x-interact:ignore><p>3</p></div>';
    vi.stubGlobal(
      'fetch',
      vi
        .fn()
        .mockResolvedValue(reply(root, { title: 'original' }, next.outerHTML)),
    );
    await bridge(root).sync();
    expect(root.querySelector('#normal')?.textContent).toBe('3');
    expect(root.querySelector('#protected')).toBe(protectedNode);
    expect(protectedNode?.className).toBe('mint');
    expect(protectedNode?.querySelector('p')?.textContent).toBe('0');
    root.querySelector('button')?.click();
    await vi.waitFor(() =>
      expect(root.querySelector('span')?.textContent).toBe('1'),
    );
  });

  it('destroys an ignored subtree when removed or its key changes, and initializes replacements once', async () => {
    const init = vi.fn();
    const destroy = vi.fn();
    Alpine.data('ownedWidget', () => ({ init, destroy }));
    const root = mount(
      '<div id="protected-0" x-interact:ignore x-data="ownedWidget"><span>0</span></div>',
    );
    const original = root.firstElementChild;
    const next = root.cloneNode(true);
    if (!(next instanceof HTMLElement)) throw new Error('Missing render.');
    next.innerHTML =
      '<div id="protected-1" x-interact:ignore x-data="ownedWidget"><span>3</span></div>';
    const fetcher = vi
      .fn()
      .mockResolvedValue(reply(root, { title: 'original' }, next.outerHTML));
    vi.stubGlobal('fetch', fetcher);
    await bridge(root).sync();
    expect(root.firstElementChild).not.toBe(original);
    expect(root.textContent).toBe('3');
    await vi.waitFor(() => {
      expect(init).toHaveBeenCalledTimes(2);
      expect(destroy).toHaveBeenCalledOnce();
    });
    next.innerHTML = '';
    fetcher.mockResolvedValue(
      reply(root, { title: 'original' }, next.outerHTML),
    );
    await bridge(root).sync();
    expect(root.children).toHaveLength(0);
    await vi.waitFor(() => expect(destroy).toHaveBeenCalledTimes(2));
  });

  it.each([
    '<div x-interact:ignore="true"></div>',
    '<div x-interact:ignore.self></div>',
  ])('rejects unsupported ignore declarations: %s', (content) => {
    expect(() => mount(content)).toThrow('without an expression or modifiers');
  });
  it('rejects protection on the island root', () => {
    const root = mount('');
    Alpine.stopObservingMutations();
    root.setAttribute('x-interact:ignore', '');
    Alpine.destroyTree(root);
    expect(() => Alpine.initTree(root)).toThrow('island descendant');
  });
});

describe('Alpine modelable bindings', () => {
  it('binds nested local modelable properties through the CSP evaluator', async () => {
    Alpine.data('nestedWidget', () => ({ selection: { country: '' } }));
    const root = mount(
      '<div x-data="nestedWidget" x-modelable="selection.country" x-interact:model="title"></div>',
    );
    expect(Alpine.evaluateRaw(wrapper(root), 'selection.country')).toBe(
      'original',
    );
    vi.stubGlobal(
      'fetch',
      vi.fn().mockResolvedValue(reply(root, { title: 'reset' })),
    );
    Alpine.evaluateRaw(wrapper(root), 'selection.country = "edited"');
    await bridge(root).sync();
    expect(Alpine.evaluateRaw(wrapper(root), 'selection.country')).toBe(
      'reset',
    );
  });
  it.each(
    ['', 0, false, null, [], ['BE'], { country: 'BE' }].map((initial) => ({
      initial,
    })),
  )(
    'initializes from server state without sending a request: $initial',
    async ({ initial }) => {
      const fetcher = vi.fn();
      vi.stubGlobal('fetch', fetcher);
      const root = mount(custom(), { title: initial });
      await Promise.resolve();
      expect(value(root)).toEqual(initial);
      expect(bridge(root).dirty).toBe(false);
      expect(fetcher).not.toHaveBeenCalled();
    },
  );

  it('captures nested edits synchronously before a same-turn action and accepts a server reset', async () => {
    const root = mount(custom('', 'form.title'), {
      form: { title: 'original' },
    });
    const fetcher = vi
      .fn()
      .mockResolvedValue(reply(root, { form: { title: 'reset by PHP' } }));
    vi.stubGlobal('fetch', fetcher);
    edit(root, 'edited before effects');
    await bridge(root).call('save');
    expect(requestBody(fetcher)).toMatchObject({
      action: 'save',
      updates: { form: { title: 'edited before effects' } },
    });
    await vi.waitFor(() => expect(value(root)).toBe('reset by PHP'));
    expect(fetcher).toHaveBeenCalledOnce();
    expect(bridge(root).dirty).toBe(false);
  });

  it('synchronizes committed changes without echoing a PHP reset', async () => {
    const root = mount(custom('.change'));
    const fetcher = vi
      .fn()
      .mockImplementation(() =>
        Promise.resolve(reply(root, { title: 'reset by PHP' })),
      );
    vi.stubGlobal('fetch', fetcher);
    await Promise.resolve();
    expect(fetcher).not.toHaveBeenCalled();
    edit(root, 'user choice');
    await vi.waitFor(() => expect(value(root)).toBe('reset by PHP'));
    expect(fetcher).toHaveBeenCalledOnce();
    expect(requestBody(fetcher)).toMatchObject({
      action: null,
      updates: { title: 'user choice' },
    });
  });

  it('debounces custom edits and cancels pending work when the wrapper is destroyed', async () => {
    vi.useFakeTimers();
    const root = mount(custom('.live.debounce.400ms'));
    const fetcher = vi
      .fn()
      .mockImplementation(() =>
        Promise.resolve(reply(root, { title: 'second' })),
      );
    vi.stubGlobal('fetch', fetcher);
    edit(root, 'first');
    await vi.advanceTimersByTimeAsync(200);
    expect(fetcher).not.toHaveBeenCalled();
    edit(root, 'second');
    await vi.advanceTimersByTimeAsync(399);
    expect(fetcher).not.toHaveBeenCalled();
    await vi.advanceTimersByTimeAsync(1);
    expect(fetcher).toHaveBeenCalledOnce();
    edit(root, 'cancel this');
    await vi.advanceTimersByTimeAsync(0);
    Alpine.destroyTree(wrapper(root));
    await vi.advanceTimersByTimeAsync(500);
    expect(fetcher).toHaveBeenCalledOnce();
  });

  it('skips a queued live synchronization while disabled and resumes on a new enabled edit', async () => {
    vi.useFakeTimers();
    const root = mount(custom('.live.debounce.400ms'));
    const fetcher = vi
      .fn()
      .mockImplementation(() =>
        Promise.resolve(reply(root, { title: 'enabled edit' })),
      );
    vi.stubGlobal('fetch', fetcher);
    edit(root, 'queued draft');
    await vi.advanceTimersByTimeAsync(0);
    expect(bridge(root).state.title).toBe('queued draft');
    wrapper(root).setAttribute('disabled', '');
    await vi.advanceTimersByTimeAsync(400);
    expect(fetcher).not.toHaveBeenCalled();
    expect(bridge(root).state.title).toBe('queued draft');
    wrapper(root).removeAttribute('disabled');
    edit(root, 'enabled edit');
    await vi.advanceTimersByTimeAsync(399);
    expect(fetcher).not.toHaveBeenCalled();
    await vi.advanceTimersByTimeAsync(1);
    expect(fetcher).toHaveBeenCalledOnce();
    expect(requestBody(fetcher)).toMatchObject({
      updates: { title: 'enabled edit' },
    });
  });

  it('does not send a queued live edit through a wrapper’s former island after moving', async () => {
    vi.useFakeTimers();
    const root = mount(custom('.live.debounce.400ms'));
    const fetcher = vi
      .fn()
      .mockImplementation(() =>
        Promise.resolve(reply(root, { title: 'queued draft' })),
      );
    vi.stubGlobal('fetch', fetcher);
    edit(root, 'queued draft');
    await vi.advanceTimersByTimeAsync(0);
    const child = mount('');
    child.append(wrapper(root));
    await vi.advanceTimersByTimeAsync(400);
    expect(fetcher).not.toHaveBeenCalled();
  });

  it('also checks native disabled controls before sending a queued live edit', async () => {
    vi.useFakeTimers();
    const root = mount('<input x-interact:model.live.debounce.400ms="title">');
    const input = root.querySelector('input');
    if (!input) throw new Error('Missing native model.');
    const fetcher = vi
      .fn()
      .mockImplementation(() =>
        Promise.resolve(reply(root, { title: 'enabled edit' })),
      );
    vi.stubGlobal('fetch', fetcher);
    input.value = 'queued draft';
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.disabled = true;
    await vi.advanceTimersByTimeAsync(400);
    expect(fetcher).not.toHaveBeenCalled();
    input.disabled = false;
    input.value = 'enabled edit';
    input.dispatchEvent(new Event('input', { bubbles: true }));
    await vi.advanceTimersByTimeAsync(400);
    expect(fetcher).toHaveBeenCalledOnce();
    expect(requestBody(fetcher)).toMatchObject({
      updates: { title: 'enabled edit' },
    });
  });

  it('flushes a pending debounce into an explicit action instead of sending a second request', async () => {
    vi.useFakeTimers();
    const root = mount(custom('.live'));
    const fetcher = vi
      .fn()
      .mockImplementation(() =>
        Promise.resolve(reply(root, { title: 'draft' })),
      );
    vi.stubGlobal('fetch', fetcher);
    edit(root, 'draft');
    await vi.advanceTimersByTimeAsync(0);
    await bridge(root).call('save');
    await vi.advanceTimersByTimeAsync(1000);
    expect(fetcher).toHaveBeenCalledOnce();
    expect(requestBody(fetcher)).toMatchObject({
      action: 'save',
      updates: { title: 'draft' },
    });
  });

  it('waits until focus leaves the wrapper for blur synchronization', async () => {
    const root = mount(custom('.blur'));
    const fetcher = vi
      .fn()
      .mockImplementation(() =>
        Promise.resolve(reply(root, { title: 'draft' })),
      );
    vi.stubGlobal('fetch', fetcher);
    edit(root, 'draft');
    const input = root.querySelector('input');
    input?.dispatchEvent(
      new FocusEvent('focusout', {
        bubbles: true,
        relatedTarget: root.querySelector('button'),
      }),
    );
    await Promise.resolve();
    expect(fetcher).not.toHaveBeenCalled();
    input?.dispatchEvent(new FocusEvent('focusout', { bubbles: true }));
    await vi.waitFor(() => expect(fetcher).toHaveBeenCalledOnce());
    expect(requestBody(fetcher)).toMatchObject({ updates: { title: 'draft' } });
  });

  it('preserves newer custom drafts during an in-flight response', async () => {
    const root = mount(custom());
    let complete: (response: Response) => void = () => {};
    const fetcher = vi.fn().mockReturnValue(
      new Promise<Response>((resolve) => {
        complete = resolve;
      }),
    );
    vi.stubGlobal('fetch', fetcher);
    edit(root, 'sent');
    const saving = bridge(root).call('save');
    edit(root, 'newer');
    complete(reply(root, { title: 'sent' }));
    await saving;
    expect(value(root)).toBe('newer');
    expect(bridge(root).state.title).toBe('newer');
    expect(bridge(root).dirty).toBe(true);
  });

  it('copies arrays so in-place edits remain observable and synchronizable', async () => {
    const root = mount(custom('.change'), { title: ['BE'] });
    const fetcher = vi
      .fn()
      .mockImplementation(() =>
        Promise.resolve(reply(root, { title: ['BE', 'NL'] })),
      );
    vi.stubGlobal('fetch', fetcher);
    Alpine.evaluateRaw(wrapper(root), "value.push('NL')");
    await vi.waitFor(() => expect(fetcher).toHaveBeenCalledOnce());
    expect(requestBody(fetcher)).toMatchObject({
      updates: { title: ['BE', 'NL'] },
    });
  });

  it('reports invalid widget values through ordinary request feedback', async () => {
    const root = mount(custom());
    edit(root, new Date());
    await vi.waitFor(() =>
      expect(bridge(root).error?.code).toBe('client_error'),
    );
    expect(bridge(root).state.title).toBe('original');
  });

  it('keeps disabled wrappers observable so they resume capture after being enabled', async () => {
    const root = mount(custom().replace('id="widget"', 'id="widget" disabled'));
    edit(root, 'disabled edit');
    await Promise.resolve();
    expect(bridge(root).state.title).toBe('original');
    wrapper(root).removeAttribute('disabled');
    edit(root, 'enabled edit');
    await vi.waitFor(() =>
      expect(bridge(root).state.title).toBe('enabled edit'),
    );
  });

  it('preserves missing custom paths and rejects clearing an existing value to undefined', async () => {
    const root = mount(custom('', 'form.title'), { form: {} });
    expect(value(root)).toBeUndefined();
    expect(bridge(root).dirty).toBe(false);
    edit(root, 'new title');
    await vi.waitFor(() =>
      expect(bridge(root).state.form).toEqual({ title: 'new title' }),
    );
    edit(root, undefined);
    await vi.waitFor(() =>
      expect(bridge(root).error?.code).toBe('client_error'),
    );
    expect(bridge(root).state.form).toEqual({ title: 'new title' });
  });

  it('rejects edits to undeclared state and reports invalid blur captures', async () => {
    const root = mount(custom('.blur', 'locked'));
    edit(root, 'forbidden');
    wrapper(root)
      .querySelector('input')
      ?.dispatchEvent(new FocusEvent('focusout', { bubbles: true }));
    expect(bridge(root).error?.code).toBe('client_error');
    expect(bridge(root).state).not.toHaveProperty('locked');
  });

  it('uses native numeric conversion for custom values', async () => {
    const root = mount(custom(), { title: 12 });
    edit(root, '0');
    await vi.waitFor(() => expect(bridge(root).state.title).toBe(0));
    edit(root, '');
    await vi.waitFor(() => expect(bridge(root).state.title).toBeNull());
  });

  it('does not observe a moved wrapper through its former island', async () => {
    const parent = mount(custom('.change'));
    const control = wrapper(parent);
    const child = mount('');
    child.append(control);
    Alpine.evaluateRaw(control, 'value = "moved"');
    await Promise.resolve();
    expect(bridge(parent).state.title).toBe('original');
  });

  it('works with application-owned standard Alpine as well as the CSP runtime', async () => {
    Alpine.stopObservingMutations();
    const { default: applicationAlpine } = await import('alpinejs');
    install(applicationAlpine);
    applicationAlpine.data('applicationWidget', () => ({ value: '' }));
    const root = document.createElement('section');
    Object.assign(root.dataset, {
      interactId: 'widgets',
      interactSnapshot: 'signed',
      interactEndpoint: '/_interact/update',
      interactState: '{"title":"original"}',
      interactWritable: '{"title":{"type":"string","nullable":false}}',
    });
    root.setAttribute('x-data', 'interact');
    root.innerHTML =
      '<div id="widget" x-data="applicationWidget" x-modelable="value" x-interact:model="title"></div>';
    document.body.append(root);
    window.Alpine = applicationAlpine;
    applicationAlpine.initTree(root);
    try {
      const client = applicationAlpine.evaluateRaw<IslandBridge>(
        root,
        '$server',
      );
      const fetcher = vi
        .fn()
        .mockResolvedValue(reply(root, { title: 'accepted' }));
      vi.stubGlobal('fetch', fetcher);
      applicationAlpine.evaluateRaw(
        wrapper(root),
        'value = "application edit"',
      );
      await client.call('save');
      expect(requestBody(fetcher)).toMatchObject({
        updates: { title: 'application edit' },
      });
      expect(applicationAlpine.evaluateRaw(wrapper(root), 'value')).toBe(
        'accepted',
      );
    } finally {
      applicationAlpine.destroyTree(root);
      root.remove();
    }
  });

  it('rejects duplicate bindings and invalid modelable paths', () => {
    expect(() =>
      mount(
        '<div x-data="testWidget" x-modelable="value" x-interact:model="title" x-interact:model.change="title"></div>',
      ),
    ).toThrow('one x-interact:model');
    expect(() =>
      mount(
        '<div x-data="testWidget" x-modelable="value()" x-interact:model="title"></div>',
      ),
    ).toThrow();
    expect(() =>
      mount(
        '<div x-data="testWidget" x-modelable="value.bad-key" x-interact:model="title"></div>',
      ),
    ).toThrow('property path');
  });
});
