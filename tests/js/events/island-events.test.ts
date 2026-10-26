import { afterEach, describe, expect, it, vi } from 'vite-plus/test';
import Alpine from '@alpinejs/csp';
import install from '../../../resources/ts/index';
import { bindIslandEvent } from '../../../resources/ts/bindings/on';

function root(id: string, content = ''): HTMLDivElement {
  const element = document.createElement('div');
  element.setAttribute(Alpine.prefixed('data'), 'interact');
  Object.assign(element.dataset, {
    interactId: id,
    interactSnapshot: 'signed',
    interactEndpoint: '/_interact/update',
    interactState: '{}',
    interactWritable: '{}',
  });
  element.innerHTML = content;
  document.body.append(element);
  return element;
}

function push(element: HTMLElement, name = 'notification') {
  element.dispatchEvent(
    new CustomEvent('interact:push', {
      bubbles: true,
      detail: {
        id: 'message-1',
        topic: 'inbox/42',
        event: name,
        detail: { text: 'Hello', percent: 0, accepted: false, empty: null },
      },
    }),
  );
}

afterEach(() => {
  for (const element of document.querySelectorAll<HTMLElement>(
    '[data-interact-id]',
  ))
    Alpine.destroyTree(element);
  document.body.innerHTML = '';
  Alpine.prefix('x-');
  vi.unstubAllGlobals();
});

describe('island-scoped event expressions', () => {
  it.each(['push', 'stream'])(
    'evaluates %s payloads and metadata in local CSP scope',
    async (source) => {
      install(Alpine);
      const metadata = source === 'push' ? '$message.topic' : '$message.name';
      const element = root(
        'owner',
        `
      <section x-data="{ value: '', metadata: '' }">
        <div x-interact:on:notification="value = $event.type + ':' + $event.detail.text"></div>
        <div x-interact:on:notification="metadata = ${metadata}"></div>
        <output x-text="value"></output><span x-text="metadata"></span>
      </section>`,
      );
      const raw = vi.fn();
      const named = vi.fn();
      element.addEventListener(`interact:${source}`, raw);
      window.addEventListener('notification', named);
      try {
        Alpine.initTree(element);
        if (source === 'push') push(element);
        else
          element.dispatchEvent(
            new CustomEvent('interact:stream', {
              bubbles: true,
              detail: { name: 'notification', detail: { text: 'Hello' } },
            }),
          );
        await vi.waitFor(() =>
          expect(element.querySelector('output')?.textContent).toBe(
            'notification:Hello',
          ),
        );
        expect(element.querySelector('span')?.textContent).toBe(
          source === 'push' ? 'inbox/42' : 'notification',
        );
        expect(raw).toHaveBeenCalledOnce();
        expect(named).not.toHaveBeenCalled();
      } finally {
        window.removeEventListener('notification', named);
      }
    },
  );

  it('filters names and nested ownership while delivering once to each matching listener', async () => {
    install(Alpine);
    const content = `<section x-data="{ count: 0 }" x-interact:on:notification="count++"><output x-text="count"></output></section>`;
    const parent = root('parent', content + content);
    const child = root('child', content);
    parent.append(child);
    const sibling = root('sibling', content);
    Alpine.initTree(parent);
    Alpine.initTree(sibling);
    push(parent, 'other');
    push(child);
    push(parent);
    await vi.waitFor(() =>
      expect(
        [...parent.querySelectorAll('output')].map(
          (element) => element.textContent,
        ),
      ).toEqual(['1', '1', '1']),
    );
    expect(sibling.querySelector('output')?.textContent).toBe('0');
  });

  it('supports method expressions and custom Alpine prefixes', async () => {
    Alpine.prefix('data-x-');
    install(Alpine);
    Alpine.data('eventHandler', () => ({
      text: '',
      receive(detail: { text: string }) {
        this.text = detail.text;
      },
    }));
    const element = root(
      'prefix',
      `<section data-x-data="eventHandler" data-x-interact:on:notification="receive($event.detail)"><output data-x-text="text"></output></section>`,
    );
    Alpine.initTree(element);
    push(element);
    await vi.waitFor(() =>
      expect(element.querySelector('output')?.textContent).toBe('Hello'),
    );
  });

  it.each([
    ['on', 'value = 1'],
    ['on:', 'value = 1'],
    ['on:42', 'value = 1'],
    ['on:notification.window', 'value = 1'],
    ['on:notification', ' '],
  ])('rejects invalid listener declarations: %s', (directive, expression) => {
    install(Alpine);
    const element = root(
      'invalid',
      `<div x-interact:${directive}="${expression}"></div>`,
    );
    expect(() => Alpine.initTree(element)).toThrow(
      'x-interact:on:event requires',
    );
  });

  it('ignores malformed envelopes and releases root listeners on directive removal', async () => {
    install(Alpine);
    const fetcher = vi.fn();
    vi.stubGlobal('fetch', fetcher);
    const element = root(
      'cleanup',
      `<div x-interact:on:notification="$server.call('save', $event.detail.text)"></div>`,
    );
    const remove = vi.spyOn(element, 'removeEventListener');
    Alpine.initTree(element);
    for (const detail of [
      null,
      [],
      {},
      { name: 'notification', detail: { invalid: Infinity } },
      { event: 'notification', detail: {} },
    ]) {
      element.dispatchEvent(new CustomEvent('interact:push', { detail }));
      element.dispatchEvent(new CustomEvent('interact:stream', { detail }));
    }
    element.dispatchEvent(new Event('interact:push'));
    const listener = element.querySelector('div');
    if (!listener) throw new Error('Missing listener.');
    Alpine.destroyTree(listener);
    listener.remove();
    push(element);
    expect(fetcher).not.toHaveBeenCalled();
    expect(remove.mock.calls.map(([name]) => name)).toContain('interact:push');
    expect(remove.mock.calls.map(([name]) => name)).toContain(
      'interact:stream',
    );
    await Promise.resolve();
  });

  it('ignores detached or reparented listeners even before lifecycle cleanup', () => {
    const owner = root('owner');
    const other = root('other');
    const element = document.createElement('div');
    owner.append(element);
    const receive = vi.fn();
    const dispose = bindIslandEvent(owner, element, 'notification', receive);
    push(owner);
    expect(receive).toHaveBeenCalledOnce();
    expect(receive.mock.calls[0]?.[0].detail).toEqual({
      text: 'Hello',
      percent: 0,
      accepted: false,
      empty: null,
    });
    other.append(element);
    push(owner);
    push(other);
    element.remove();
    push(owner);
    expect(receive).toHaveBeenCalledOnce();
    dispose();
    owner.append(element);
    push(owner);
    expect(receive).toHaveBeenCalledOnce();
  });

  it('reports rejected application handlers without losing other event deliveries', async () => {
    const report = vi.fn();
    vi.stubGlobal('reportError', report);
    const element = root('rejection');
    const failure = new Error('Application handler failed.');
    const dispose = bindIslandEvent(element, element, 'notification', () =>
      Promise.reject(failure),
    );
    const other = vi.fn();
    const disposeOther = bindIslandEvent(
      element,
      element,
      'notification',
      other,
    );
    push(element);
    await vi.waitFor(() => expect(report).toHaveBeenCalledWith(failure));
    expect(other).toHaveBeenCalledOnce();
    dispose();
    disposeOther();
  });

  it('passes explicit action arguments through the existing server bridge', async () => {
    install(Alpine);
    const element = root(
      'action',
      `<div x-interact:on:report:ready="$server.call('openReport', $event.detail.text, $message.id)"></div>`,
    );
    const fetcher = vi
      .fn()
      .mockResolvedValue(new Response('', { status: 503 }));
    vi.stubGlobal('fetch', fetcher);
    Alpine.initTree(element);
    push(element, 'report:ready');
    await vi.waitFor(() => expect(fetcher).toHaveBeenCalledOnce());
    const init: unknown = fetcher.mock.calls[0]?.[1];
    if (
      !init ||
      typeof init !== 'object' ||
      !('body' in init) ||
      typeof init.body !== 'string'
    )
      throw new Error('Missing request payload.');
    expect(JSON.parse(init.body)).toMatchObject({
      action: 'openReport',
      args: ['Hello', 'message-1'],
    });
  });
});
