import { afterEach, describe, expect, it, vi } from 'vitest';
import Alpine from '@alpinejs/csp';
import install from '../../resources/ts/index';

function root(id: string, content = ''): HTMLDivElement {
  const element = document.createElement('div');
  element.setAttribute('x-data', 'interact');
  element.dataset.interactId = id;
  element.dataset.interactState = '{"title":"original"}';
  element.dataset.interactWritable =
    '{"title":{"type":"string","nullable":false}}';
  element.dataset.interactSnapshot = 'signed';
  element.dataset.interactEndpoint = '/_interact/update';
  element.innerHTML = content;
  return element;
}
function reply(element: HTMLElement, html: string): Response {
  return new Response(
    JSON.stringify({
      version: 1,
      id: element.dataset.interactId,
      html,
      snapshot: 'next',
      state: { title: 'original' },
      writable: { title: { type: 'string', nullable: false } },
      errors: {},
      events: [],
    }),
    { headers: { 'Content-Type': 'application/json' } },
  );
}
afterEach(() => {
  for (const element of document.querySelectorAll<HTMLElement>(
    '[data-interact-id]',
  ))
    Alpine.destroyTree(element);
  document.body.innerHTML = '';
  vi.unstubAllGlobals();
});

describe('Alpine installation and morphing', () => {
  it('installs once, provides the bridge, preserves children and local Alpine state', async () => {
    const plugin = vi.spyOn(Alpine, 'plugin');
    const magic = vi.spyOn(Alpine, 'magic');
    install(Alpine);
    install(Alpine);
    expect(plugin).toHaveBeenCalledOnce();
    const callback: unknown = magic.mock.calls.find(
      ([name]) => name === 'server',
    )?.[1];
    if (typeof callback !== 'function') throw new Error('Missing server magic');
    expect(() =>
      Reflect.apply(callback, undefined, [document.createElement('div')]),
    ).toThrow('inside');
    const parent = root(
      'parent',
      '<button data-interact-click="refresh">Refresh</button><p x-text="$server.state.title"></p><div x-data="{ open: false }"><button @click="open = !open">Toggle</button><span x-show="open">Local state</span></div>',
    );
    const child = root(
      'child',
      '<input data-interact-model="title" value="original">',
    );
    parent.append(child);
    document.body.append(parent);
    Alpine.initTree(parent);
    const toggle = parent.querySelector(':scope > [x-data] > button');
    if (!(toggle instanceof HTMLButtonElement))
      throw new Error('Missing local toggle');
    toggle.click();
    await vi.waitFor(() =>
      expect(parent.querySelector('span')?.style.display).not.toBe('none'),
    );
    const input = child.querySelector('input');
    if (!(input instanceof HTMLInputElement))
      throw new Error('Missing child input');
    input.value = 'child draft';
    input.dispatchEvent(new Event('input', { bubbles: true }));
    const replacement = parent.cloneNode(true);
    if (!(replacement instanceof HTMLElement))
      throw new Error('Missing replacement');
    const childReplacement = replacement.querySelector(
      '[data-interact-id="child"]',
    );
    const placeholder = document.createElement('div');
    placeholder.dataset.interactPreserve = 'child';
    childReplacement?.replaceWith(placeholder);
    const fetcher = vi
      .fn()
      .mockResolvedValue(reply(parent, replacement.outerHTML));
    vi.stubGlobal('fetch', fetcher);
    const button = parent.querySelector('button');
    if (!(button instanceof HTMLButtonElement))
      throw new Error('Missing refresh button');
    button.click();
    await vi.waitFor(() =>
      expect(parent.dataset.interactSnapshot).toBe('signed'),
    );
    await vi.waitFor(() => expect(parent.getAttribute('aria-busy')).toBeNull());
    expect(fetcher).toHaveBeenCalledOnce();
    expect(parent.querySelector('[data-interact-id="child"]')).toBe(child);
    expect(child.querySelector('input')).toBe(input);
    expect(input.value).toBe('child draft');
    expect(parent.querySelector('span')?.style.display).not.toBe('none');
  });
  it('disposes requests when Alpine destroys an island', async () => {
    install(Alpine);
    const element = root(
      'disposal',
      '<button data-interact-click="save">Save</button>',
    );
    document.body.append(element);
    Alpine.initTree(element);
    let complete: (response: Response) => void = () => {};
    const fetcher = vi.fn().mockReturnValue(
      new Promise<Response>((resolve) => {
        complete = resolve;
      }),
    );
    vi.stubGlobal('fetch', fetcher);
    element.querySelector('button')?.click();
    Alpine.destroyTree(element);
    complete(reply(element, element.outerHTML));
    await vi.waitFor(() =>
      expect(element.getAttribute('aria-busy')).toBeNull(),
    );
    const options: unknown = fetcher.mock.calls[0]?.[1];
    expect(options).toHaveProperty('signal.aborted', true);
  });
  it('evaluates shorthand actions with typed arguments in the CSP build', async () => {
    install(Alpine);
    const element = root(
      'arguments',
      '<button @click="$server.call(\'adjust\', -1)" :disabled="$server.busy">Decrease</button>',
    );
    const fetcher = vi
      .fn()
      .mockResolvedValue(reply(element, element.outerHTML));
    vi.stubGlobal('fetch', fetcher);
    document.body.append(element);
    Alpine.initTree(element);
    element.querySelector('button')?.click();
    await vi.waitFor(() => expect(fetcher).toHaveBeenCalledOnce());
    const options: unknown = fetcher.mock.calls[0]?.[1];
    expect(options).toHaveProperty('body');
    if (
      typeof options !== 'object' ||
      options === null ||
      !('body' in options) ||
      typeof options.body !== 'string'
    )
      throw new Error('Missing JSON request body');
    expect(JSON.parse(options.body)).toMatchObject({
      action: 'adjust',
      args: [-1],
    });
    await vi.waitFor(() =>
      expect(element.getAttribute('aria-busy')).toBeNull(),
    );
  });
  it('starts the standalone CSP build and prevents a second Alpine runtime', async () => {
    delete window.Alpine;
    const start = vi.spyOn(Alpine, 'start').mockImplementation(() => {});
    const standalone = await import('../../resources/ts/standalone');
    expect(start).toHaveBeenCalledOnce();
    expect(window.Alpine).toBe(Alpine);
    expect(() => standalone.start()).toThrow('already loaded');
    delete window.Alpine;
  });
});
