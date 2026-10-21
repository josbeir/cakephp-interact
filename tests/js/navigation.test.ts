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
  });

  it('binds links outside islands, handles prefetch focus and disposes every listener', async () => {
    vi.useFakeTimers();
    const { controller } = setup();
    const visit = vi.spyOn(controller, 'visit').mockResolvedValue(false);
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
    expect(await controller.visit('/second')).toBe(true);
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
    expect(await controller.visit('/canceled')).toBe(false);
    expect(fetcher).not.toHaveBeenCalled();
    const error = vi.fn();
    document.addEventListener('interact:navigation-error', error, {
      once: true,
    });
    fetcher.mockRejectedValueOnce(new Error('offline'));
    expect(await controller.visit('/failed', { replace: true })).toBe(true);
    expect(native).toHaveBeenCalledWith(
      new URL('/failed', window.location.href).href,
      true,
    );
    expect(error).toHaveBeenCalledOnce();
    expect(document.querySelector('#old')).not.toBeNull();
    expect(await controller.visit('https://external.example/')).toBe(true);
    expect(native).toHaveBeenLastCalledWith('https://external.example/', false);
    controller.dispose();
    expect(await controller.visit('/disposed')).toBe(false);
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
    expect(await latest).toBe(true);
    first(pageResponse('<main>Stale</main>'));
    expect(await old).toBe(false);
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
    ).toBe(true);
    expect(native).not.toHaveBeenCalled();
    controller.dispose();
    expect(
      await enhancedRedirect({ url: '/native', replace: false }, native),
    ).toBe(true);
    expect(native).toHaveBeenCalledOnce();
  });

  it('falls back for unmarked pages and releases resources on page removal', async () => {
    document.head.innerHTML = '';
    const { controller, native } = setup();
    controller.prefetch('/never');
    controller.query(new URL('/query', window.location.href), true);
    expect(window.location.pathname).toBe('/query');
    expect(await controller.visit('/native')).toBe(true);
    expect(native).toHaveBeenCalledWith('/native', false);
    window.dispatchEvent(
      new PageTransitionEvent('pagehide', { persisted: true }),
    );
    expect(await controller.visit('/still-native')).toBe(true);
    window.dispatchEvent(new PageTransitionEvent('pagehide'));
    expect(await controller.visit('/disposed')).toBe(false);
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
