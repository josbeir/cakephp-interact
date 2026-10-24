import {
  afterEach,
  beforeEach,
  describe,
  expect,
  it,
  vi,
} from 'vite-plus/test';
import Alpine from '@alpinejs/csp';
import { NavigationController } from '../../resources/ts/navigation/controller';
import { NavigationActivity } from '../../resources/ts/navigation/activity';
import {
  documentIdentity,
  incomingDocument,
} from '../../resources/ts/navigation/document';
import {
  retainedRegions,
  preserveRegions,
  regions,
} from '../../resources/ts/navigation/persist';
import {
  linkDestination,
  updateCurrent,
} from '../../resources/ts/navigation/links';
import { navigationBinding } from '../../resources/ts/navigation/alpine';
import {
  navigation,
  enhancedRedirect,
} from '../../resources/ts/navigation/registry';
import type { Fetcher } from '../../resources/ts/types';
import type { UrlFields } from '../../resources/ts/url/types';

const identity = {
  version: '1',
  application: '',
  session: 'session',
  policy: 'policy',
};
function marker(value: unknown = identity): string {
  return `<meta name="interact-navigation" content='${JSON.stringify(value)}'>`;
}
function html(
  body = '<main><h1>Second page</h1></main>',
  head = marker(),
): string {
  return `<!doctype html><html lang="fr"><head><title>Second</title>${head}</head><body class="destination">${body}</body></html>`;
}
function pageResponse(body?: string): Response {
  return new Response(html(body), { headers: { 'Content-Type': 'text/html' } });
}
const controllers: NavigationController[] = [];
function setup(fetcher: Fetcher = async () => pageResponse()) {
  const native = vi.fn();
  const refresh = vi.fn();
  const controller = new NavigationController(Alpine, refresh, native, fetcher);
  controllers.push(controller);
  return { controller, native, refresh };
}
beforeEach(() => {
  window.history.replaceState({ application: 'retained' }, '', '/first');
  document.head.innerHTML = marker();
  document.body.innerHTML = '<main id="old">First</main>';
  vi.spyOn(window, 'scrollTo').mockImplementation(() => {});
});
afterEach(() => {
  controllers.splice(0).forEach((controller) => controller.dispose());
  Alpine.destroyTree(document.body);
  document.body.replaceChildren();
  document.head.replaceChildren();
  document.documentElement.removeAttribute('aria-busy');
  vi.useRealTimers();
  vi.restoreAllMocks();
});

describe('navigation documents and persistence', () => {
  it('validates complete HTML and all document compatibility boundaries', () => {
    expect(documentIdentity(document)).toEqual(identity);
    expect(incomingDocument(html(), document).documentElement.lang).toBe('fr');
    for (const field of Object.keys(identity)) {
      expect(() =>
        incomingDocument(
          html(undefined, marker({ ...identity, [field]: 'changed' })),
          document,
        ),
      ).toThrow('fresh browser');
    }
    for (const body of [
      '<p>Fragment</p>',
      html(undefined, ''),
      html(undefined, marker() + '<base href="/elsewhere">'),
      html(undefined, marker() + '<meta http-equiv="refresh" content="1">'),
    ])
      expect(() => incomingDocument(body, document)).toThrow();
    for (const value of [null, {}, { ...identity, policy: 1 }]) {
      document.head.innerHTML = marker(value);
      expect(documentIdentity(document)).toBeUndefined();
    }
    document.head.innerHTML =
      '<meta name="interact-navigation" content="broken">';
    expect(documentIdentity(document)).toBeUndefined();
    document.head.innerHTML = marker() + marker();
    expect(documentIdentity(document)).toBeUndefined();
  });

  it('matches literal regions and rejects ambiguous ownership', () => {
    document.body.innerHTML =
      '<aside x-interact:persist="layout"><div data-interact-id="old" data-interact-mount="same" data-interact-endpoint="/update"></div></aside>';
    const next = incomingDocument(
      html(
        '<aside x-interact:persist="layout"><div data-interact-id="new" data-interact-mount="same" data-interact-endpoint="/update"></div></aside>',
      ),
      document,
    );
    expect(retainedRegions(document, next, 'x-interact:persist')).toHaveLength(
      1,
    );
    next
      .querySelector('[data-interact-mount]')
      ?.setAttribute('data-interact-mount', 'different');
    expect(retainedRegions(document, next, 'x-interact:persist')).toEqual([]);
    next.body.innerHTML = '<section x-interact:persist="layout"></section>';
    expect(retainedRegions(document, next, 'x-interact:persist')).toEqual([]);
    next.body.innerHTML = '<aside x-interact:persist="another-layout"></aside>';
    expect(retainedRegions(document, next, 'x-interact:persist')).toEqual([]);
    for (const invalid of [
      '<div x-interact:persist=""></div>',
      '<div x-interact:persist="same"></div><div x-interact:persist="same"></div>',
      '<div x-interact:persist="outer"><div x-interact:persist="inner"></div></div>',
      '<div data-interact-id="page"><div x-interact:persist="nested"></div></div>',
    ]) {
      document.body.innerHTML = invalid;
      expect(() => regions(document, 'x-interact:persist')).toThrow();
    }
    document.body.innerHTML = '';
    document.body.setAttribute('x-interact:persist', 'whole-body');
    expect(() => regions(document, 'x-interact:persist')).toThrow();
    document.body.removeAttribute('x-interact:persist');
    document.head.innerHTML = '<div x-interact:persist="head-region"></div>';
    expect(() => regions(document, 'x-interact:persist')).toThrow();
  });

  it('restores selection and both scroll axes of retained regions', () => {
    document.body.innerHTML =
      '<aside><input value="persistent draft"><div>Scrollable</div></aside>';
    const aside = document.querySelector('aside');
    const input = document.querySelector('input');
    if (!aside || !input) throw new Error('Missing fixture.');
    aside.scrollTop = 50;
    aside.scrollLeft = 20;
    input.focus();
    input.setSelectionRange(2, 6);
    const restore = preserveRegions([{ current: aside, incoming: aside }]);
    aside.scrollTop = 0;
    aside.scrollLeft = 0;
    input.blur();
    input.setSelectionRange(0, 0);
    restore();
    expect(document.activeElement).toBe(input);
    expect(input.selectionStart).toBe(2);
    expect(input.selectionEnd).toBe(6);
    expect(aside.scrollTop).toBe(50);
    expect(aside.scrollLeft).toBe(20);
  });
});

describe('navigation feedback and links', () => {
  it('shares delayed query timers without delaying visits or flashing on fast requests', async () => {
    vi.useFakeTimers();
    const activity = new NavigationActivity(Alpine.reactive);
    expect(activity.store.pending).toBe(false);
    expect(activity.store.loading()).toBe(false);
    activity.start();
    expect(activity.store.pending).toBe(true);
    expect(activity.store.loading()).toBe(false);
    expect(activity.store.loading({ delay: 300 })).toBe(false);
    expect(activity.store.loading({ delay: 0 })).toBe(true);
    await vi.advanceTimersByTimeAsync(200);
    expect(activity.store.loading()).toBe(true);
    expect(activity.store.loading({ delay: 300 })).toBe(false);
    activity.start();
    await vi.advanceTimersByTimeAsync(100);
    expect(activity.store.loading({ delay: 300 })).toBe(true);
    activity.finish();
    expect(activity.store.loading()).toBe(false);
    expect(activity.store.pending).toBe(false);
    expect(vi.getTimerCount()).toBe(0);
    activity.start();
    expect(() => activity.store.loading({ delay: -1 })).toThrow();
    activity.finish();
  });

  it('keeps modified, targeted, downloaded, external, and fragment links native', () => {
    const link = document.createElement('a');
    link.href = '/second';
    expect(linkDestination(link)?.pathname).toBe('/second');
    for (const options of [
      { ctrlKey: true },
      { metaKey: true },
      { shiftKey: true },
      { altKey: true },
      { button: 1 },
    ])
      expect(
        linkDestination(link, new MouseEvent('click', options)),
      ).toBeUndefined();
    const prevented = new MouseEvent('click', { cancelable: true });
    prevented.preventDefault();
    expect(linkDestination(link, prevented)).toBeUndefined();
    for (const [name, value] of [
      ['download', ''],
      ['target', '_blank'],
      ['rel', 'external'],
    ]) {
      if (name === undefined || value === undefined)
        throw new Error('Missing attribute.');
      link.setAttribute(name, value);
      expect(linkDestination(link)).toBeUndefined();
      link.removeAttribute(name);
    }
    for (const href of [
      'https://external.example/path',
      'javascript:alert(1)',
      '/first#anchor',
    ]) {
      link.href = href;
      expect(linkDestination(link)).toBeUndefined();
    }
    link.removeAttribute('href');
    expect(linkDestination(link)).toBeUndefined();
    link.href = '/first/?q=filter#anchor';
    updateCurrent(link);
    expect(link.getAttribute('aria-current')).toBe('page');
    link.href = '/first/child';
    updateCurrent(link);
    expect(link.hasAttribute('aria-current')).toBe(false);
    link.setAttribute('aria-current', 'step');
    updateCurrent(link);
    expect(link.getAttribute('aria-current')).toBe('step');
    link.href = '/first?q=changed';
    expect(linkDestination(link)?.search).toBe('?q=changed');
    link.href = '/first';
    link.target = '_SELF';
    expect(linkDestination(link)?.pathname).toBe('/first');
    window.history.replaceState(null, '', '/first#current');
    expect(linkDestination(link)).toBeUndefined();
    window.history.replaceState(null, '', '/');
    link.href = '/?q=filter#anchor';
    updateCurrent(link);
    expect(link.getAttribute('aria-current')).toBe('page');
  });

  it.each([
    ['/item', '/item', true, true],
    ['/item', '/item/subpage', false, true],
    ['/item', '/item/subpage/edit', false, true],
    ['/item', '/items', false, false],
    ['/item', '/item-other', false, false],
    ['/item', '/Item/subpage', false, false],
    ['/item/subpage', '/item', false, false],
    ['/item/?from=menu#top', '/item?filter=active#content', true, true],
    ['/item///?from=menu#top', '/item/subpage/?filter=active', false, true],
    ['/', '/', true, true],
    ['/?from=menu#top', '/item', false, false],
    ['https://external.example/item', '/item', false, false],
    ['https://external.example/item', '/item/subpage', false, false],
    ['https://localhost/item', '/item/subpage', false, false],
    ['http://localhost:8080/item', '/item/subpage', false, false],
  ])(
    'matches %s on %s with exact=%s and trail=%s',
    (href, location, exact, trail) => {
      window.history.replaceState(null, '', location);
      const link = document.createElement('a');
      link.href = href;
      updateCurrent(link, true);
      expect(link.getAttribute('aria-current')).toBe(exact ? 'page' : null);
      expect(link.hasAttribute('data-interact-active-trail')).toBe(trail);
      updateCurrent(link);
      expect(link.hasAttribute('data-interact-active-trail')).toBe(false);
      expect(link.getAttribute('aria-current')).toBe(exact ? 'page' : null);
    },
  );

  it('refreshes opted-in trails on URL and destination changes until disposed', async () => {
    const { controller } = setup();
    const link = document.createElement('a');
    link.href = '/first';
    document.body.append(link);
    const dispose = navigationBinding(
      controller,
      link,
      'navigate',
      ['trail'],
      '',
    );
    expect(link.getAttribute('aria-current')).toBe('page');
    expect(link.hasAttribute('data-interact-active-trail')).toBe(true);
    window.history.replaceState(null, '', '/first/child');
    document.dispatchEvent(new Event('interact:url'));
    expect(link.hasAttribute('aria-current')).toBe(false);
    expect(link.hasAttribute('data-interact-active-trail')).toBe(true);
    window.history.replaceState(null, '', '/elsewhere');
    document.dispatchEvent(new Event('interact:url'));
    expect(link.hasAttribute('data-interact-active-trail')).toBe(false);
    link.href = '/elsewhere';
    await Promise.resolve();
    expect(link.getAttribute('aria-current')).toBe('page');
    expect(link.hasAttribute('data-interact-active-trail')).toBe(true);
    link.setAttribute('aria-current', 'step');
    link.href = '/first';
    await Promise.resolve();
    expect(link.getAttribute('aria-current')).toBe('step');
    expect(link.hasAttribute('data-interact-active-trail')).toBe(false);
    dispose();
    link.href = '/elsewhere';
    await Promise.resolve();
    document.dispatchEvent(new Event('interact:url'));
    expect(link.getAttribute('aria-current')).toBe('step');
    expect(link.hasAttribute('data-interact-active-trail')).toBe(false);
  });

  it('binds links outside islands, handles prefetch focus and disposes every listener', async () => {
    vi.useFakeTimers();
    const { controller } = setup();
    const visit = vi.spyOn(controller, 'visit').mockResolvedValue('cancelled');
    const prefetch = vi.spyOn(controller, 'prefetch');
    const link = document.createElement('a');
    link.href = '/second';
    document.body.append(link);
    const dispose = navigationBinding(
      controller,
      link,
      'navigate',
      ['hover', 'preserve-scroll'],
      '',
    );
    link.dispatchEvent(new Event('mouseenter'));
    link.dispatchEvent(new Event('mouseleave'));
    await vi.advanceTimersByTimeAsync(60);
    expect(prefetch).not.toHaveBeenCalled();
    link.dispatchEvent(new Event('focus'));
    await vi.advanceTimersByTimeAsync(60);
    expect(prefetch).toHaveBeenCalledOnce();
    link.dispatchEvent(
      new MouseEvent('click', { cancelable: true, ctrlKey: true }),
    );
    expect(visit).not.toHaveBeenCalled();
    link.dispatchEvent(
      new MouseEvent('click', { cancelable: true, button: 0 }),
    );
    expect(visit).toHaveBeenCalledWith(
      new URL('/second', window.location.href).href,
      { preserveScroll: true },
    );
    link.href = '/first';
    await Promise.resolve();
    expect(link.getAttribute('aria-current')).toBe('page');
    window.history.replaceState(null, '', '/second');
    document.dispatchEvent(new Event('interact:url'));
    expect(link.hasAttribute('aria-current')).toBe(false);
    link.href = 'https://external.example/page';
    link.dispatchEvent(new Event('focus'));
    await vi.advanceTimersByTimeAsync(60);
    expect(prefetch).toHaveBeenCalledOnce();
    dispose();
    link.click();
    link.dispatchEvent(new Event('focus'));
    await vi.advanceTimersByTimeAsync(60);
    expect(prefetch).toHaveBeenCalledOnce();
    expect(
      navigationBinding(
        controller,
        document.createElement('div'),
        'persist',
        [],
        'player',
      ),
    ).toBeTypeOf('function');
    for (const [element, value, modifiers, expression] of [
      [document.createElement('div'), 'navigate', [], ''],
      [link, 'navigate', ['wrong'], ''],
      [link, 'navigate', [], 'dynamic'],
      [link, 'persist', [], ''],
      [link, 'persist', ['wrong'], 'key'],
    ] as const)
      expect(() =>
        navigationBinding(
          controller,
          element,
          value,
          [...modifiers],
          expression,
        ),
      ).toThrow();
  });

  it.each([false, true])(
    'joins a pressed request on click with hover enabled: %s',
    async (hover) => {
      vi.useFakeTimers();
      let complete: ((response: Response) => void) | undefined;
      const fetcher = vi.fn<Fetcher>(
        () =>
          new Promise((resolve) => {
            complete = resolve;
          }),
      );
      const { controller, native } = setup(fetcher);
      const link = document.createElement('a');
      link.href = '/second';
      document.body.append(link);
      const dispose = navigationBinding(
        controller,
        link,
        'navigate',
        hover ? ['hover'] : [],
        '',
      );
      if (hover) {
        link.dispatchEvent(new Event('mouseenter'));
        await vi.advanceTimersByTimeAsync(60);
      }
      const press = new MouseEvent('mousedown', {
        button: 0,
        cancelable: true,
      });
      link.dispatchEvent(press);
      expect(press.defaultPrevented).toBe(false);
      expect(fetcher).toHaveBeenCalledOnce();
      expect(window.location.pathname).toBe('/first');
      expect(document.getElementById('old')).not.toBeNull();
      link.click();
      expect(fetcher).toHaveBeenCalledOnce();
      complete?.(pageResponse());
      await vi.runAllTimersAsync();
      expect(window.location.pathname).toBe('/second');
      expect(document.querySelector('h1')?.textContent).toBe('Second page');
      expect(native).not.toHaveBeenCalled();
      dispose();
    },
  );

  it.each([
    ['private, max-age=30', 1],
    ['no-store', 2],
    ['no-cache', 2],
    ['', 2],
  ] as const)(
    'honors %s when a pressed request finishes before click',
    async (policy, requests) => {
      vi.useFakeTimers();
      const fetcher = vi.fn<Fetcher>(async () => {
        const response = pageResponse();
        response.headers.set('Cache-Control', policy);
        return response;
      });
      const { controller, native } = setup(fetcher);
      const link = document.createElement('a');
      link.href = '/second';
      document.body.append(link);
      const dispose = navigationBinding(controller, link, 'navigate', [], '');
      link.dispatchEvent(new MouseEvent('mousedown', { button: 0 }));
      await vi.runAllTimersAsync();
      expect(fetcher).toHaveBeenCalledOnce();
      expect(window.location.pathname).toBe('/first');
      link.click();
      await vi.runAllTimersAsync();
      expect(fetcher).toHaveBeenCalledTimes(requests);
      expect(window.location.pathname).toBe('/second');
      expect(native).not.toHaveBeenCalled();
      dispose();
    },
  );

  it('ignores ineligible presses and removes mouse-down behavior on disposal', () => {
    const { controller } = setup();
    const prefetch = vi
      .spyOn(controller, 'prefetch')
      .mockImplementation(() => {});
    const link = document.createElement('a');
    link.href = '/second';
    document.body.append(link);
    const dispose = navigationBinding(controller, link, 'navigate', [], '');
    for (const options of [
      { button: 1 },
      { button: 2 },
      { ctrlKey: true },
      { metaKey: true },
      { shiftKey: true },
      { altKey: true },
    ])
      link.dispatchEvent(new MouseEvent('mousedown', options));
    const prevented = new MouseEvent('mousedown', { cancelable: true });
    prevented.preventDefault();
    link.dispatchEvent(prevented);
    for (const [name, value] of [
      ['download', ''],
      ['target', '_blank'],
      ['rel', 'external'],
    ] as const) {
      link.setAttribute(name, value);
      link.dispatchEvent(new MouseEvent('mousedown'));
      link.removeAttribute(name);
    }
    for (const href of ['https://external.example/page', '/first#section']) {
      link.href = href;
      link.dispatchEvent(new MouseEvent('mousedown'));
    }
    expect(prefetch).not.toHaveBeenCalled();
    link.href = '/second';
    link.dispatchEvent(new MouseEvent('mousedown'));
    expect(prefetch).toHaveBeenCalledOnce();
    dispose();
    link.dispatchEvent(new MouseEvent('mousedown'));
    expect(prefetch).toHaveBeenCalledOnce();
  });

  it('lets no-prefetch override hover without disabling enhanced clicks', async () => {
    vi.useFakeTimers();
    const { controller } = setup();
    const prefetch = vi.spyOn(controller, 'prefetch');
    const visit = vi.spyOn(controller, 'visit').mockResolvedValue('cancelled');
    const link = document.createElement('a');
    link.href = '/second';
    document.body.append(link);
    const dispose = navigationBinding(
      controller,
      link,
      'navigate',
      ['no-prefetch', 'hover'],
      '',
    );
    link.dispatchEvent(new Event('mouseenter'));
    link.dispatchEvent(new Event('focus'));
    link.dispatchEvent(new MouseEvent('mousedown'));
    await vi.advanceTimersByTimeAsync(120);
    expect(prefetch).not.toHaveBeenCalled();
    link.click();
    expect(visit).toHaveBeenCalledOnce();
    dispose();
    link.click();
    expect(visit).toHaveBeenCalledOnce();
  });

  it('cancels delayed hover work when a press or keyboard click starts navigation', async () => {
    vi.useFakeTimers();
    const { controller } = setup();
    const prefetch = vi
      .spyOn(controller, 'prefetch')
      .mockImplementation(() => {});
    vi.spyOn(controller, 'visit').mockResolvedValue('cancelled');
    const link = document.createElement('a');
    link.href = '/second';
    document.body.append(link);
    const dispose = navigationBinding(
      controller,
      link,
      'navigate',
      ['hover'],
      '',
    );
    link.dispatchEvent(new Event('mouseenter'));
    link.dispatchEvent(new MouseEvent('mousedown'));
    await vi.advanceTimersByTimeAsync(60);
    expect(prefetch).toHaveBeenCalledOnce();
    link.dispatchEvent(new Event('focus'));
    link.click();
    await vi.advanceTimersByTimeAsync(60);
    expect(prefetch).toHaveBeenCalledOnce();
    dispose();
  });
});

describe('document visit controller', () => {
  it('commits fresh content, metadata and attributes while preserving application history state', async () => {
    const { controller, native } = setup();
    const events: string[] = [];
    const onEvent = (event: Event) => events.push(event.type);
    for (const name of [
      'interact:navigate',
      'interact:navigating',
      'interact:navigated',
    ])
      document.addEventListener(name, onEvent);
    await Promise.resolve();
    events.length = 0;
    expect(await controller.visit('/second')).toBe('enhanced');
    expect(document.title).toBe('Second');
    expect(document.body.className).toBe('destination');
    expect(document.documentElement.lang).toBe('fr');
    expect(document.querySelector('main')?.textContent).toBe('Second page');
    expect(window.location.pathname).toBe('/second');
    expect(window.history.state).toMatchObject({
      application: 'retained',
      interactNavigation: { index: 1 },
    });
    expect(events).toEqual([
      'interact:navigate',
      'interact:navigating',
      'interact:navigated',
    ]);
    expect(native).not.toHaveBeenCalled();
    expect(controller.activity.store.pending).toBe(false);
    expect(document.documentElement.hasAttribute('aria-busy')).toBe(false);
    for (const name of [
      'interact:navigate',
      'interact:navigating',
      'interact:navigated',
    ])
      document.removeEventListener(name, onEvent);
  });

  it('retains real nodes and Alpine state and refreshes compatible island context', async () => {
    const content =
      '<aside x-interact:persist="player" x-data="{ playing: true }"><input value="draft"><div data-interact-id="new" data-interact-mount="same" data-interact-endpoint="/update" data-interact-csrf="fresh"></div></aside><main>New page</main>';
    document.body.innerHTML = content.replace(
      'data-interact-id="new"',
      'data-interact-id="old"',
    );
    Alpine.initTree(document.body);
    const retained = document.querySelector('aside');
    const input = document.querySelector('input');
    if (!retained || !input) throw new Error('Missing retained fixture.');
    input.focus();
    input.setSelectionRange(1, 3);
    const { controller, refresh } = setup(async () => pageResponse(content));
    await controller.visit('/second');
    expect(document.querySelector('aside')).toBe(retained);
    expect(document.activeElement).toBe(input);
    expect(Alpine.evaluateRaw(retained, 'playing')).toBe(true);
    expect(refresh).toHaveBeenCalledOnce();
    expect(
      document
        .querySelector('[data-interact-id]')
        ?.getAttribute('data-interact-id'),
    ).toBe('old');
  });

  it('allows leave cancellation and falls back only on genuine failures', async () => {
    const fetcher = vi.fn<Fetcher>(async () => pageResponse());
    const { controller, native } = setup(fetcher);
    const cancel = (event: Event) => event.preventDefault();
    document.addEventListener('interact:navigate', cancel, { once: true });
    expect(await controller.visit('/canceled')).toBe('cancelled');
    expect(fetcher).not.toHaveBeenCalled();
    const error = vi.fn();
    document.addEventListener('interact:navigation-error', error, {
      once: true,
    });
    fetcher.mockRejectedValueOnce(new Error('offline'));
    expect(await controller.visit('/failed', { replace: true })).toBe('native');
    expect(native).toHaveBeenCalledWith(
      new URL('/failed', window.location.href).href,
      true,
    );
    expect(error).toHaveBeenCalledOnce();
    expect(document.querySelector('#old')).not.toBeNull();
    expect(await controller.visit('https://external.example/')).toBe('native');
    expect(native).toHaveBeenLastCalledWith('https://external.example/', false);
    controller.dispose();
    expect(await controller.visit('/disposed')).toBe('cancelled');
  });

  it('lets the latest visit win even when fetch ignores abort', async () => {
    let first: (response: Response) => void = () => {};
    const fetcher = vi
      .fn<Fetcher>()
      .mockImplementationOnce(
        () =>
          new Promise((resolve) => {
            first = resolve;
          }),
      )
      .mockResolvedValue(pageResponse());
    const { controller, native } = setup(fetcher);
    const old = controller.visit('/old');
    const latest = controller.visit('/latest');
    expect(await latest).toBe('enhanced');
    first(pageResponse('<main>Stale</main>'));
    expect(await old).toBe('cancelled');
    expect(window.location.pathname).toBe('/latest');
    expect(document.querySelector('main')?.textContent).toBe('Second page');
    expect(native).not.toHaveBeenCalled();
  });

  it('preserves explicit scroll, handles fragments, and shares enhanced redirects', async () => {
    const { controller } = setup(async () =>
      pageResponse('<main><h2 id="fragment">Target</h2></main>'),
    );
    const scroll = vi.fn();
    HTMLElement.prototype.scrollIntoView = scroll;
    await controller.visit('/second#fragment');
    expect(scroll).toHaveBeenCalledOnce();
    await controller.visit('/third', { preserveScroll: true, replace: true });
    expect(window.scrollTo).toHaveBeenLastCalledWith(0, 0);
    expect(window.history.state).toMatchObject({
      interactNavigation: { index: 1 },
    });
    expect(navigation(window)).toBe(controller);
    const native = vi.fn();
    expect(
      await enhancedRedirect({ url: '/fourth', replace: false }, native),
    ).toBe('enhanced');
    expect(native).not.toHaveBeenCalled();
    controller.dispose();
    expect(
      await enhancedRedirect({ url: '/native', replace: false }, native),
    ).toBe('native');
    expect(native).toHaveBeenCalledOnce();
  });

  it('falls back for unmarked pages and releases resources on page removal', async () => {
    document.head.innerHTML = '';
    const { controller, native } = setup();
    controller.prefetch('/never');
    controller.query(new URL('/query', window.location.href), true);
    expect(window.location.pathname).toBe('/query');
    const length = window.history.length;
    controller.query(new URL('/replaced-query', window.location.href), false);
    expect(window.location.pathname).toBe('/replaced-query');
    expect(window.history.length).toBe(length);
    expect(await controller.visit('/native')).toBe('native');
    expect(native).toHaveBeenCalledWith('/native', false);
    window.dispatchEvent(
      new PageTransitionEvent('pagehide', { persisted: true }),
    );
    expect(await controller.visit('/still-native')).toBe('native');
    window.dispatchEvent(new PageTransitionEvent('pagehide'));
    expect(await controller.visit('/disposed')).toBe('cancelled');
  });
});

it('preserves unsupported application history shapes by choosing native navigation', async () => {
  window.history.replaceState('application-owned-value', '', '/first');
  const { controller, native } = setup();
  await controller.visit('/second');
  expect(native).toHaveBeenCalledWith('/second', false);
  expect(window.history.state).toBe('application-owned-value');
});

it('continues restoring same-page URL state when application history events omit Interact metadata', async () => {
  const { pageUrl } = await import('../../resources/ts/url/coordinator');
  const restored = vi.spyOn(pageUrl(window), 'restore');
  const { native } = setup();
  window.history.replaceState({ application: true }, '', '/first?filter=new');
  window.dispatchEvent(new PopStateEvent('popstate'));
  expect(restored).toHaveBeenCalledOnce();
  expect(native).not.toHaveBeenCalled();
  expect(window.history.state).toEqual({ application: true });
});

it('fetches fresh documents for history visits, restores entry scroll, and reverses canceled Back without a replacement entry', async () => {
  const { pageEntry } = await import('../../resources/ts/navigation/history');
  const fetcher = vi.fn<Fetcher>(async () => pageResponse());
  const { controller, native } = setup(fetcher);
  const first = pageEntry(window.history.state);
  if (!first) throw new Error('Missing initial history entry.');
  await controller.visit('/second');
  const second = pageEntry(window.history.state);
  if (!second) throw new Error('Missing destination history entry.');
  window.history.replaceState(
    { interactNavigation: { ...first, x: 12, y: 345 } },
    '',
    '/first',
  );
  window.dispatchEvent(
    new PopStateEvent('popstate', { state: window.history.state }),
  );
  await vi.waitFor(() => expect(fetcher).toHaveBeenCalledTimes(2));
  await vi.waitFor(() =>
    expect(window.scrollTo).toHaveBeenLastCalledWith(12, 345),
  );
  expect(pageEntry(window.history.state)?.page).toBe(first.page);
  expect(native).not.toHaveBeenCalled();
  const go = vi.spyOn(window.history, 'go').mockImplementation(() => {});
  const replace = vi.spyOn(window.history, 'replaceState');
  window.history.replaceState({ interactNavigation: second }, '', '/second');
  replace.mockClear();
  document.addEventListener(
    'interact:navigate',
    (event) => event.preventDefault(),
    { once: true },
  );
  window.dispatchEvent(
    new PopStateEvent('popstate', { state: window.history.state }),
  );
  await vi.waitFor(() => expect(go).toHaveBeenCalledWith(-1));
  expect(replace).not.toHaveBeenCalled();
  expect(fetcher).toHaveBeenCalledTimes(2);
});

it('restores same-page entries without fetching and chooses native navigation for foreign history entries', async () => {
  const { pageEntry } = await import('../../resources/ts/navigation/history');
  const { pageUrl } = await import('../../resources/ts/url/coordinator');
  const fetcher = vi.fn<Fetcher>(async () => pageResponse());
  const { controller, native } = setup(fetcher);
  const restore = vi.spyOn(pageUrl(window), 'restore');
  const initial = pageEntry(window.history.state);
  if (!initial) throw new Error('Missing initial entry.');
  controller.query(new URL('/first?q=new', window.location.href), true);
  expect(pageEntry(window.history.state)).toMatchObject({
    page: initial.page,
    index: 1,
  });
  window.history.replaceState(
    { interactNavigation: { ...initial, x: 10, y: 20 } },
    '',
    '/first',
  );
  window.dispatchEvent(
    new PopStateEvent('popstate', { state: window.history.state }),
  );
  expect(restore).toHaveBeenCalledOnce();
  expect(window.scrollTo).toHaveBeenLastCalledWith(10, 20);
  expect(fetcher).not.toHaveBeenCalled();
  window.history.replaceState(null, '', '/another-application-page');
  window.dispatchEvent(new PopStateEvent('popstate'));
  expect(native).toHaveBeenCalledWith(window.location.href, true);
});

it('invalidates unused prefetches and stops pending visits and startup events on disposal', async () => {
  const fetcher = vi.fn<Fetcher>(async () => pageResponse());
  const { controller } = setup(fetcher);
  controller.prefetch(new URL('/prefetched', window.location.href).href);
  expect(fetcher).toHaveBeenCalledOnce();
  const prefetchSignal = fetcher.mock.calls[0]?.[1].signal;
  controller.invalidate();
  expect(prefetchSignal?.aborted).toBe(true);
  const started = vi.fn();
  document.addEventListener('interact:navigated', started);
  controller.dispose();
  await Promise.resolve();
  expect(started).not.toHaveBeenCalled();
  document.removeEventListener('interact:navigated', started);
  controller.prefetch(new URL('/never', window.location.href).href);
  expect(fetcher).toHaveBeenCalledOnce();

  let resolve: (response: Response) => void = () => {};
  const pendingFetcher = vi.fn<Fetcher>(
    () =>
      new Promise((done) => {
        resolve = done;
      }),
  );
  const { controller: pending, native } = setup(pendingFetcher);
  const visit = pending.visit('/pending');
  pending.dispose();
  expect(pendingFetcher.mock.calls[0]?.[1].signal?.aborted).toBe(true);
  resolve(pageResponse());
  await expect(visit).resolves.toBe('cancelled');
  expect(native).not.toHaveBeenCalled();
  expect(document.querySelector('#old')).not.toBeNull();
  expect(document.documentElement.hasAttribute('aria-busy')).toBe(false);
});

it('cleans up temporary main focus, honors existing autofocus, and tolerates malformed fragment encoding', async () => {
  const fetcher = vi
    .fn<Fetcher>()
    .mockResolvedValueOnce(pageResponse('<main>Focus destination</main>'))
    .mockResolvedValueOnce(
      pageResponse('<main><input autofocus tabindex="2"></main>'),
    )
    .mockResolvedValueOnce(
      pageResponse('<section>No main or autofocus</section>'),
    );
  const { controller } = setup(fetcher);
  await controller.visit('/second');
  const main = document.querySelector('main');
  expect(document.activeElement).toBe(main);
  expect(main?.getAttribute('tabindex')).toBe('-1');
  main?.blur();
  expect(main?.hasAttribute('tabindex')).toBe(false);
  await controller.visit('/third#%FF');
  expect(document.activeElement).toBe(document.querySelector('input'));
  expect(document.querySelector('input')?.getAttribute('tabindex')).toBe('2');
  expect(window.scrollTo).toHaveBeenLastCalledWith(0, 0);
  await controller.visit('/fourth');
  expect(document.querySelector('section')?.textContent).toBe(
    'No main or autofocus',
  );
});

it('uses native assign or replace and reloads terminal same-page fragment destinations', async () => {
  const { nativeVisit } =
    await import('../../resources/ts/navigation/controller');
  const assign = vi.fn();
  const replace = vi.fn();
  const reload = vi.fn();
  vi.stubGlobal('window', {
    location: {
      href: 'https://example.test/current?q=one',
      assign,
      replace,
      reload,
    },
  });
  try {
    nativeVisit('/next', false);
    expect(assign).toHaveBeenCalledWith('https://example.test/next');
    expect(reload).not.toHaveBeenCalled();
    nativeVisit('/next?q=two', true);
    expect(replace).toHaveBeenCalledWith('https://example.test/next?q=two');
    nativeVisit('/current?q=one#section', false);
    expect(reload).toHaveBeenCalledOnce();
    nativeVisit('https://other.test/current?q=one#section', false);
    nativeVisit('/current?q=two#section', false);
    expect(reload).toHaveBeenCalledOnce();
  } finally {
    vi.unstubAllGlobals();
  }
});

it('merges URL-bound state through page history and keeps restoration generations across owner replacement', async () => {
  const { pageEntry } = await import('../../resources/ts/navigation/history');
  const { pageUrl } = await import('../../resources/ts/url/coordinator');
  const { controller } = setup();
  const coordinator = pageUrl(window);
  const initial = pageEntry(window.history.state);
  const restored = vi.fn();
  const fields: UrlFields = {
    filter: { name: 'q', type: 'string', default: '', history: true },
  };
  const registration = coordinator.register(
    fields,
    { filter: 'initial' },
    restored,
  );
  await Promise.resolve();
  expect(window.location.search).toBe('?q=initial');
  expect(pageEntry(window.history.state)).toMatchObject({
    page: initial?.page,
    index: 0,
  });
  registration.accept({ filter: 'edited' }, registration.epoch(), false);
  expect(window.location.search).toBe('?q=edited');
  expect(pageEntry(window.history.state)).toMatchObject({
    page: initial?.page,
    index: 1,
  });
  window.history.replaceState(
    { interactNavigation: initial },
    '',
    '/first?q=earlier',
  );
  window.dispatchEvent(
    new PopStateEvent('popstate', { state: window.history.state }),
  );
  expect(restored).toHaveBeenCalledOnce();
  expect(restored).toHaveBeenLastCalledWith({
    values: { filter: 'earlier' },
    epoch: registration.epoch(),
  });
  const epoch = registration.epoch();
  coordinator.suspend();
  registration.accept({ filter: 'late' }, epoch, false);
  expect(window.location.search).toBe('?q=earlier');
  coordinator.resume(true);
  expect(restored).toHaveBeenCalledWith({
    values: { filter: 'earlier' },
    epoch: epoch + 2,
  });
  registration.dispose();
  expect(pageUrl(window)).toBe(coordinator);
  const replacement = coordinator.register(
    fields,
    { filter: 'replacement' },
    restored,
  );
  expect(replacement.epoch()).toBe(epoch + 2);
  await Promise.resolve();
  expect(window.location.search).toBe('?q=replacement');
  replacement.dispose();
  controller.dispose();
  const finalOwner = coordinator.register(fields, undefined, restored);
  finalOwner.dispose();
  expect(pageUrl(window)).not.toBe(coordinator);
});
