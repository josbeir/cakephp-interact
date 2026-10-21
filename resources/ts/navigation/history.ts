import { record } from '../state/values';

/** Unsupported application history shapes keep native navigation rather than being overwritten. */
export function supportsPageHistory(value: unknown): boolean {
  return value === null || record(value);
}

export interface PageEntry {
  page: string;
  index: number;
  x: number;
  y: number;
}

/** Read only Interact's namespace; application-owned history keys remain untouched. */
export function pageEntry(value: unknown): PageEntry | undefined {
  if (!record(value) || !record(value.interactNavigation)) return;
  const entry = value.interactNavigation;
  if (
    typeof entry.page !== 'string' ||
    !entry.page ||
    !Number.isInteger(entry.index) ||
    typeof entry.index !== 'number' ||
    typeof entry.x !== 'number' ||
    !Number.isFinite(entry.x) ||
    typeof entry.y !== 'number' ||
    !Number.isFinite(entry.y)
  )
    return;
  return { page: entry.page, index: entry.index, x: entry.x, y: entry.y };
}

/**
 * Coordinates visit and query entries without caching rendered HTML or signed island state.
 * Entry indices also allow canceling a history visit without adding a replacement entry.
 */
export class NavigationHistory {
  current: PageEntry;
  protected readonly previousScroll: ScrollRestoration;
  protected restoring: number | undefined;
  protected currentUrl: string;
  protected readonly hash = () => {
    if (this.host.location.href === this.currentUrl) return;
    const active = pageEntry(this.host.history.state);
    if (
      active &&
      (active.page !== this.current.page || active.index !== this.current.index)
    )
      return;
    // Native fragment entries may clear or inherit history.state. Give each its own position
    // so a canceled multi-entry Back can return to the actual originating entry.
    this.current = {
      ...this.current,
      index: this.current.index + 1,
      x: this.host.scrollX,
      y: this.host.scrollY,
    };
    this.write(this.current, this.host.location.href, false);
    this.visit(this.current);
  };
  protected readonly scroll = () => {
    const active = pageEntry(this.host.history.state);
    if (
      active?.index === this.current.index &&
      active.page === this.current.page &&
      this.host.location.href === this.currentUrl
    )
      this.save();
  };
  protected readonly pop = (event: PopStateEvent) => {
    const entry = pageEntry(event.state);
    const before = new URL(this.currentUrl);
    const after = this.host.location;
    if (
      (!entry ||
        (entry.page === this.current.page &&
          entry.index === this.current.index)) &&
      before.pathname === after.pathname &&
      before.search === after.search &&
      before.hash !== after.hash
    ) {
      this.hash();
      return;
    }
    if (this.restoring !== undefined && entry?.index === this.restoring) {
      this.restoring = undefined;
      this.currentUrl = this.host.location.href;
      return;
    }
    this.visit(entry);
  };

  constructor(
    protected readonly host: Window,
    protected readonly visit: (entry: PageEntry | undefined) => void,
  ) {
    this.previousScroll = host.history.scrollRestoration;
    this.currentUrl = host.location.href;
    this.current = pageEntry(host.history.state) ?? {
      page: [...crypto.getRandomValues(new Uint8Array(16))]
        .map((value) => value.toString(16).padStart(2, '0'))
        .join(''),
      index: 0,
      x: host.scrollX,
      y: host.scrollY,
    };
    this.write(this.current, host.location.href, false);
    host.history.scrollRestoration = 'manual';
    host.addEventListener('popstate', this.pop);
    host.addEventListener('scroll', this.scroll, { passive: true });
    host.addEventListener('hashchange', this.hash);
  }

  save(): void {
    this.current = {
      ...this.current,
      x: this.host.scrollX,
      y: this.host.scrollY,
    };
    this.write(this.current, this.host.location.href, false);
  }

  query(url: URL, push: boolean): void {
    this.save();
    this.current = {
      ...this.current,
      index: this.current.index + (push ? 1 : 0),
    };
    this.write(this.current, url.href, push);
  }

  commit(url: URL, replace: boolean, restored?: PageEntry): void {
    this.current = restored ?? {
      page: [...crypto.getRandomValues(new Uint8Array(16))]
        .map((value) => value.toString(16).padStart(2, '0'))
        .join(''),
      index: this.current.index + (replace ? 0 : 1),
      x: 0,
      y: 0,
    };
    this.write(this.current, url.href, !restored && !replace);
  }

  sameDocument(): boolean {
    return new URL(this.currentUrl).pathname === this.host.location.pathname;
  }

  acceptQuery(entry: PageEntry): void {
    this.current = entry;
    this.currentUrl = this.host.location.href;
  }

  cancel(entry: PageEntry): void {
    this.restoring = this.current.index;
    this.host.history.go(this.current.index - entry.index);
  }

  dispose(): void {
    this.host.removeEventListener('popstate', this.pop);
    this.host.removeEventListener('scroll', this.scroll);
    this.host.removeEventListener('hashchange', this.hash);
    this.host.history.scrollRestoration = this.previousScroll;
  }

  protected write(entry: PageEntry, url: string, push: boolean): void {
    const state: unknown = this.host.history.state;
    this.currentUrl = url;
    this.host.history[push ? 'pushState' : 'replaceState'](
      { ...(record(state) ? state : {}), interactNavigation: entry },
      '',
      url,
    );
  }
}
