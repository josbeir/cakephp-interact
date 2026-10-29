import { afterEach, describe, expect, it, vi } from 'vite-plus/test';
import Alpine from '@alpinejs/csp';
import install from '../../../resources/ts/index';
import { modelBinding } from '../../../resources/ts/bindings/model';
import type { IslandBridge } from '../../../resources/ts/types';

function root(content: string): HTMLDivElement {
  const element = document.createElement('div');
  element.setAttribute('x-data', 'interact');
  Object.assign(element.dataset, {
    interactId: 'directives',
    interactSnapshot: 'signed',
    interactEndpoint: '/_interact/update',
    interactState: '{"title":"original"}',
    interactWritable: '{"title":{"type":"string","nullable":false}}',
  });
  element.innerHTML = content;
  document.body.append(element);
  return element;
}
function bridge(element: HTMLElement): IslandBridge {
  return Alpine.evaluateRaw<IslandBridge>(element, '$server');
}
function input(element: HTMLElement): HTMLInputElement {
  const found = element.querySelector('input');
  if (!found) throw new Error('Missing input.');
  return found;
}
function form(element: HTMLElement): HTMLFormElement {
  const found = element.querySelector('form');
  if (!found) throw new Error('Missing form.');
  return found;
}
afterEach(() => {
  for (const element of document.querySelectorAll<HTMLElement>(
    '[data-interact-id]',
  ))
    Alpine.destroyTree(element);
  document.body.innerHTML = '';
  vi.useRealTimers();
  vi.unstubAllGlobals();
});

describe('Alpine directive contracts', () => {
  it('parses literal paths and explicit synchronization policies', () => {
    expect(modelBinding('form.title', [])).toEqual({
      path: 'form.title',
      timing: 'defer',
      delay: 250,
      explicitDebounce: false,
    });
    expect(modelBinding('title', ['live', 'debounce'])).toEqual({
      path: 'title',
      timing: 'live',
      delay: 250,
      explicitDebounce: true,
    });
    expect(modelBinding('title', ['live']).explicitDebounce).toBe(false);
    expect(modelBinding('title', ['live', 'debounce', '0ms']).delay).toBe(0);
    expect(modelBinding('title', ['live', 'debounce', '60000ms']).delay).toBe(
      60000,
    );
    for (const modifiers of [
      ['live', 'blur'],
      ['live', 'live'],
      ['debounce'],
      ['change', 'debounce'],
      ['live', 'debounce', '60001ms'],
      ['live', 'debounce', '1.5ms'],
      ['live', '300ms'],
      ['unknown'],
      ['live', 'debounce', '30ms', 'debounce'],
    ])
      expect(() => modelBinding('title', modifiers)).toThrow();
    for (const path of ['', 'form..title', 'form.__proto__.title'])
      expect(() => modelBinding(path, [])).toThrow();
  });

  it.each([
    ['<div x-interact:model="title"></div>', 'requires an input'],
    [
      '<input x-model="$server.state.title" x-interact:model="title">',
      'cannot be combined',
    ],
    [
      '<input x-model.lazy="$server.state.title" x-interact:model="title">',
      'cannot be combined',
    ],
    [
      '<input x-interact:model="title" x-interact:model.blur="title">',
      'one x-interact:model',
    ],
    ['<input x-interact:submit="$server.sync()">', 'requires a form'],
    [
      '<form x-interact:submit.prevent="$server.sync()"></form>',
      'without modifiers',
    ],
    ['<form x-interact:submit=" "></form>', 'non-empty'],
    ['<input x-interact:unknown="title">', 'Unknown x-interact'],
    [
      '<input x-interact:model.live.blur="title">',
      'one x-interact:model synchronization',
    ],
  ])('rejects invalid declarations: %s', (content, message) => {
    install(Alpine);
    const element = root(content);
    expect(() => Alpine.initTree(element)).toThrow(message);
  });

  it('uses local Alpine scope and the native submit event with typed action arguments', async () => {
    install(Alpine);
    const element = root(
      '<div x-data="{ articleId: 42 }"><form x-interact:submit="$server.call(\'save\', articleId, $event.type)"><input x-interact:model="title"><button>Save</button></form></div>',
    );
    const fetcher = vi
      .fn()
      .mockResolvedValue(new Response('', { status: 503 }));
    vi.stubGlobal('fetch', fetcher);
    Alpine.initTree(element);
    input(element).value = 'unblurred draft';
    form(element).requestSubmit();
    form(element).requestSubmit();
    await vi.waitFor(() => expect(bridge(element).pending()).toBe(false));
    expect(fetcher).toHaveBeenCalledOnce();
    const options: unknown = fetcher.mock.calls[0]?.[1];
    if (
      typeof options !== 'object' ||
      options === null ||
      !('body' in options) ||
      typeof options.body !== 'string'
    )
      throw new Error('Missing request.');
    expect(JSON.parse(options.body)).toMatchObject({
      action: 'save',
      args: [42, 'submit'],
      updates: { title: 'unblurred draft' },
    });
    expect(element.querySelector('button')?.disabled).toBe(false);
  });

  it.each(['missingAction()', 'fail()'])(
    'releases form protection and reports expression failures: %s',
    async (expression) => {
      install(Alpine);
      const element = root(
        '<form><input x-interact:model="title"><button>Save</button></form>',
      );
      form(element).setAttribute('x-interact:submit', expression);
      if (expression === 'fail()') {
        form(element).setAttribute('x-data', 'rejectingSubmission');
        Alpine.data('rejectingSubmission', () => ({
          fail: () => Promise.reject(new Error('private diagnostics')),
        }));
        form(element).setAttribute('x-interact:submit', 'fail()');
      }
      Alpine.initTree(element);
      input(element).value = 'retained';
      input(element).dispatchEvent(new Event('input', { bubbles: true }));
      form(element).requestSubmit();
      await vi.waitFor(() =>
        expect(bridge(element).error?.code).toBe('client_error'),
      );
      expect(bridge(element).error?.message).not.toContain(
        'private diagnostics',
      );
      expect(input(element).value).toBe('retained');
      expect(element.querySelector('button')?.disabled).toBe(false);
      expect(form(element).hasAttribute('aria-busy')).toBe(false);
    },
  );

  it('cancels unsent work when a model directive is removed and ignores unbound controls', async () => {
    vi.useFakeTimers();
    install(Alpine);
    const element = root(
      '<input x-interact:model.live="title"><input name="unbound">',
    );
    const fetcher = vi.fn();
    vi.stubGlobal('fetch', fetcher);
    Alpine.initTree(element);
    input(element).value = 'draft';
    input(element).dispatchEvent(new Event('input', { bubbles: true }));
    Alpine.destroyTree(input(element));
    input(element).value = 'unregistered';
    input(element).dispatchEvent(new Event('input', { bubbles: true }));
    element
      .querySelector('input[name]')
      ?.dispatchEvent(new Event('change', { bubbles: true }));
    await vi.runAllTimersAsync();
    expect(fetcher).not.toHaveBeenCalled();
    expect(bridge(element).state.title).toBe('draft');
  });

  it('publishes invalid native model edits through normal failure feedback', () => {
    install(Alpine);
    const element = root('<input x-interact:model="locked">');
    Alpine.initTree(element);
    input(element).dispatchEvent(new Event('input', { bubbles: true }));
    expect(bridge(element).error?.code).toBe('client_error');
    expect(bridge(element).paused).toBe(true);
  });

  it('does not invoke the original island after a form moves across an ownership boundary', () => {
    install(Alpine);
    const element = root(
      '<form x-interact:submit="$server.call(\'save\')"><button>Save</button></form>',
    );
    const child = root('');
    child.dataset.interactId = 'child';
    element.append(child);
    const fetcher = vi.fn();
    vi.stubGlobal('fetch', fetcher);
    Alpine.initTree(element);
    const moved = form(element);
    child.append(moved);
    const event = new Event('submit', { bubbles: true, cancelable: true });
    moved.dispatchEvent(event);
    expect(event.defaultPrevented).toBe(false);
    expect(fetcher).not.toHaveBeenCalled();
  });
});
