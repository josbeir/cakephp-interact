import type { Alpine } from 'alpinejs';
import { destination } from '../effects/browser';
import { pageUrl } from '../url/coordinator';
import type { Fetcher } from '../types';
import { NavigationActivity } from './activity';
import { PageAssets } from './assets';
import { copyAttributes, documentIdentity, incomingDocument } from './document';
import { NavigationHistory, supportsPageHistory } from './history';
import type { PageEntry } from './history';
import { PageFetcher } from './prefetch';
import { preserveRegions, retainedRegions } from './persist';
import { registerNavigation } from './registry';
import type { NavigationService, VisitOptions } from './registry';

export interface NavigationDetail {
  url: URL;
  history: boolean;
}

/** Native fallback repeats only a page GET and never replays an island action. */
export function nativeVisit(url: string, replace: boolean): void {
  const before = new URL(window.location.href);
  const next = new URL(url, before);
  window.location[replace ? 'replace' : 'assign'](next.href);
  if (
    before.origin === next.origin &&
    before.pathname === next.pathname &&
    before.search === next.search &&
    (before.hash || next.hash)
  )
    window.location.reload();
}

/**
 * Owns document visits while island queues continue owning their own server operations.
 *
 * Only the latest visit may commit. Persistent nodes are moved outside Alpine cleanup,
 * and browser history distinguishes fresh page visits from island query restoration.
 */
export class NavigationController implements NavigationService {
  readonly activity: NavigationActivity;
  protected readonly assets = new PageAssets(document);
  protected readonly pages: PageFetcher;
  protected readonly history: NavigationHistory | undefined;
  protected readonly unregister: () => void;
  protected generation = 0;
  protected pending: AbortController | undefined;
  protected targetEntry: PageEntry | undefined;
  protected disposed = false;
  protected readonly hide = (event: PageTransitionEvent) => {
    if (!event.persisted) this.dispose();
  };

  constructor(
    protected readonly Alpine: Alpine,
    protected readonly refresh: (
      root: HTMLElement,
      incoming: HTMLElement,
    ) => void,
    protected readonly native: (
      url: string,
      replace: boolean,
    ) => void = nativeVisit,
    fetcher?: Fetcher,
  ) {
    this.pages = new PageFetcher(fetcher);
    this.activity = new NavigationActivity(Alpine.reactive);
    Alpine.store('interactNavigation', this.activity.store);
    if (documentIdentity(document) && supportsPageHistory(window.history.state))
      this.history = new NavigationHistory(window, (entry) =>
        this.restore(entry),
      );
    this.unregister = this.history
      ? registerNavigation(window, this)
      : () => {};
    window.addEventListener('pagehide', this.hide);
    queueMicrotask(() => {
      if (!this.disposed)
        this.emit('interact:navigated', {
          url: new URL(window.location.href),
          history: false,
        });
    });
  }

  epoch(): number {
    return this.generation;
  }

  invalidate(): void {
    this.pages.clear();
  }

  prefetch(url: string): void {
    if (!this.disposed && documentIdentity(document) && destination(url, true))
      this.pages.prefetch(url);
  }

  query(url: URL, push: boolean): void {
    if (this.history) this.history.query(url, push);
    else
      window.history[push ? 'pushState' : 'replaceState'](
        window.history.state,
        '',
        url,
      );
    document.dispatchEvent(new Event('interact:url'));
  }

  async visit(url: string, options: VisitOptions = {}): Promise<boolean> {
    if (this.disposed) return false;
    if (!destination(url, true) || !this.history) {
      this.native(url, options.replace ?? false);
      return true;
    }
    const next = new URL(url, window.location.href);
    const detail = { url: next, history: options.history ?? false };
    if (!this.emit('interact:navigate', detail, true)) return false;
    const entry = options.history ? this.targetEntry : undefined;
    if (!options.history) this.history.save();
    this.pending?.abort();
    const request = new AbortController();
    this.pending = request;
    const generation = ++this.generation;
    const query = pageUrl(window);
    query.suspend();
    this.activity.start();
    document.documentElement.setAttribute('aria-busy', 'true');
    const scroll = { x: window.scrollX, y: window.scrollY };
    try {
      const response = await this.pages.take(next.href, request.signal);
      this.current(request, generation);
      const incoming = incomingDocument(response.html, document);
      const activate = await this.assets.prepare(
        incoming,
        response.url,
        request.signal,
      );
      this.current(request, generation);
      const retained = retainedRegions(
        document,
        incoming,
        this.Alpine.prefixed('interact:persist'),
      );
      const final = new URL(response.url, next);
      this.emit('interact:navigating', { ...detail, url: final });
      this.current(request, generation);
      const restore = preserveRegions(retained);
      const focused = document.activeElement;
      const keptFocus =
        focused instanceof HTMLElement &&
        retained.some(({ current }) => current.contains(focused));
      this.Alpine.mutateDom(() => {
        for (const { current, incoming: replacement } of retained) {
          const before = [
            ...current.querySelectorAll<HTMLElement>('[data-interact-id]'),
          ];
          const after = [
            ...replacement.querySelectorAll<HTMLElement>('[data-interact-id]'),
          ];
          before.forEach((root, index) => {
            const fresh = after[index];
            if (fresh) this.refresh(root, fresh);
          });
          current.remove();
          replacement.replaceWith(current);
        }
        this.Alpine.destroyTree(document.body);
        this.history?.commit(final, options.replace ?? false, entry);
        this.assets.head(incoming);
        activate();
        copyAttributes(incoming.documentElement, document.documentElement);
        document.documentElement.setAttribute('aria-busy', 'true');
        copyAttributes(incoming.body, document.body);
        document.body.replaceChildren(...incoming.body.childNodes);
        query.resume(true);
        const kept = new Set(retained.map(({ current }) => current));
        this.Alpine.initTree(document.body, undefined, (element, skip) => {
          if (kept.has(element)) skip();
        });
      });
      await this.Alpine.nextTick();
      this.current(request, generation);
      restore();
      this.position(
        final,
        entry ?? (options.preserveScroll ? scroll : undefined),
        keptFocus,
      );
      this.pages.clear();
      document.dispatchEvent(new Event('interact:url'));
      this.emit('interact:navigated', { ...detail, url: final });
      return true;
    } catch (cause: unknown) {
      if (
        request.signal.aborted ||
        generation !== this.generation ||
        this.disposed
      )
        return false;
      request.abort();
      this.emit('interact:navigation-error', { ...detail, cause });
      this.native(next.href, options.replace ?? false);
      return true;
    } finally {
      if (this.pending === request) {
        this.pending = undefined;
        query.resume(false);
        this.activity.finish();
        document.documentElement.removeAttribute('aria-busy');
      }
    }
  }

  dispose(): void {
    this.disposed = true;
    this.pending?.abort();
    this.pending = undefined;
    this.activity.finish();
    this.pages.clear();
    this.history?.dispose();
    this.unregister();
    window.removeEventListener('pagehide', this.hide);
    document.documentElement.removeAttribute('aria-busy');
  }

  protected current(request: AbortController, generation: number): void {
    if (this.disposed || generation !== this.generation) request.abort();
    request.signal.throwIfAborted();
  }

  protected restore(entry: PageEntry | undefined): void {
    if (
      this.history &&
      (entry?.page === this.history.current.page ||
        (!entry && this.history.sameDocument()))
    ) {
      this.generation++;
      this.pending?.abort();
      this.history.acceptQuery(entry ?? this.history.current);
      pageUrl(window).restore();
      if (entry) window.scrollTo(entry.x, entry.y);
      document.dispatchEvent(new Event('interact:url'));
      return;
    }
    if (!entry || !this.history) {
      this.native(window.location.href, true);
      return;
    }
    this.targetEntry = entry;
    void this.visit(window.location.href, { history: true }).then(
      (committed) => {
        if (!committed && this.targetEntry === entry && !this.pending)
          this.history?.cancel(entry);
      },
    );
  }

  protected position(
    url: URL,
    scroll: { x: number; y: number } | undefined,
    keptFocus: boolean,
  ): void {
    if (!keptFocus) {
      const focus =
        document.querySelector<HTMLElement>('[autofocus]') ??
        document.querySelector<HTMLElement>('main, [role="main"]');
      if (focus) {
        const tabindex = focus.getAttribute('tabindex');
        if (tabindex === null) focus.setAttribute('tabindex', '-1');
        focus.focus({ preventScroll: true });
        if (tabindex === null)
          focus.addEventListener(
            'blur',
            () => focus.removeAttribute('tabindex'),
            { once: true },
          );
      }
    }
    if (scroll) {
      window.scrollTo(scroll.x, scroll.y);
      return;
    }
    let fragment: HTMLElement | null = null;
    try {
      fragment = document.getElementById(decodeURIComponent(url.hash.slice(1)));
    } catch {
      fragment = null;
    }
    if (url.hash && fragment) fragment.scrollIntoView();
    else window.scrollTo(0, 0);
  }

  protected emit(
    name: string,
    detail: NavigationDetail | (NavigationDetail & { cause: unknown }),
    cancelable = false,
  ): boolean {
    return document.dispatchEvent(
      new CustomEvent(name, { bubbles: true, cancelable, detail }),
    );
  }
}
