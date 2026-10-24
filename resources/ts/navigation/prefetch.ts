import type { Fetcher } from '../types';

interface PrefetchEntry {
  controller: AbortController;
  promise: Promise<PageResponse>;
  value?: PageResponse;
  press?: AbortSignal;
}

export interface PageResponse {
  html: string;
  url: string;
  cacheUntil: number;
}

/**
 * Holds bounded hover responses and the GET owned by an active mouse press.
 * Completed press responses belong only to that gesture; later reuse requires HTTP freshness.
 * Detached HTML never initializes Alpine or owns polling, upload, or lazy-load activity.
 */
export class PageFetcher {
  protected readonly entries = new Map<string, PrefetchEntry>();

  constructor(
    protected readonly fetcher: Fetcher = (url, options) => fetch(url, options),
  ) {}

  prefetch(url: string, press?: AbortSignal): void {
    if (press?.aborted) return;
    const previous = this.entries.get(url);
    if (
      previous &&
      (!previous.value ||
        previous.value.cacheUntil > Date.now() ||
        (!press && previous.press && !previous.press.aborted))
    ) {
      if (press) this.hold(url, previous, press);
      return;
    }
    this.entries.delete(url);
    while (this.entries.size >= 8) {
      const oldest = this.entries.keys().next().value;
      if (oldest) {
        this.entries.get(oldest)?.controller.abort();
        this.entries.delete(oldest);
      }
    }
    const controller = new AbortController();
    const entry: PrefetchEntry = {
      controller,
      promise: this.fetch(url, controller.signal),
    };
    this.entries.set(url, entry);
    if (press) this.hold(url, entry, press);
    void entry.promise.then(
      (value) => {
        if (this.entries.get(url) !== entry) return;
        entry.value = value;
        if (value.cacheUntil <= Date.now() && !entry.press)
          this.entries.delete(url);
      },
      () => {
        if (this.entries.get(url) === entry) this.entries.delete(url);
      },
    );
  }

  async take(
    url: string,
    signal: AbortSignal,
    press?: AbortSignal,
  ): Promise<PageResponse> {
    const entry = this.entries.get(url);
    const owned =
      press !== undefined && !press.aborted && entry?.press === press;
    if (
      !entry ||
      (entry.value && entry.value.cacheUntil <= Date.now() && !owned)
    )
      return this.fetch(url, signal);
    this.entries.delete(url);
    const abort = () => entry.controller.abort();
    signal.addEventListener('abort', abort, { once: true });
    try {
      signal.throwIfAborted();
      return await entry.promise;
    } finally {
      signal.removeEventListener('abort', abort);
    }
  }

  clear(): void {
    for (const entry of this.entries.values()) entry.controller.abort();
    this.entries.clear();
  }

  /** Keep early responses for their originating gesture and release abandoned work. */
  protected hold(url: string, entry: PrefetchEntry, press: AbortSignal): void {
    if (entry.press === press) return;
    entry.press = press;
    press.addEventListener(
      'abort',
      () => {
        if (this.entries.get(url) !== entry || entry.press !== press) return;
        delete entry.press;
        if (!entry.value || entry.value.cacheUntil <= Date.now()) {
          this.entries.delete(url);
          entry.controller.abort();
        }
      },
      { once: true },
    );
  }

  protected async fetch(
    url: string,
    signal: AbortSignal,
  ): Promise<PageResponse> {
    const response = await this.fetcher(url, {
      method: 'GET',
      cache: 'no-store',
      credentials: 'same-origin',
      headers: { Accept: 'text/html' },
      signal,
    });
    if (
      !response.ok ||
      response.status !== 200 ||
      !response.headers
        .get('Content-Type')
        ?.toLowerCase()
        .includes('text/html') ||
      response.headers.has('Content-Disposition')
    )
      throw new Error('Navigation requires a successful HTML document.');
    const final = new URL(response.url || url, url);
    if (final.origin !== new URL(url).origin)
      throw new Error('Navigation left the current origin.');
    // Fetch's response URL omits the requested fragment, which still belongs to the visit.
    if (!final.hash) final.hash = new URL(url).hash;
    const cache = response.headers.get('Cache-Control') ?? '';
    const freshness = /(?:^|,)\s*max-age=(\d+)/i.exec(cache)?.[1];
    const age = Number(response.headers.get('Age') ?? 0);
    const ttl =
      freshness && !/\b(?:no-store|no-cache)\b/i.test(cache)
        ? Math.min(30, Math.max(0, Number(freshness) - age))
        : 0;
    return {
      html: await response.text(),
      url: final.href,
      cacheUntil: Date.now() + ttl * 1000,
    };
  }
}
