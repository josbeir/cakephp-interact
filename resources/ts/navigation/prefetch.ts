import type { Fetcher } from '../types';

export interface PageResponse {
  html: string;
  url: string;
  cacheUntil: number;
}

/**
 * Holds bounded, single-use prefetch responses only for explicitly fresh cacheable documents.
 * Detached HTML never initializes Alpine or owns polling, upload, or lazy-load activity.
 */
export class PageFetcher {
  protected readonly entries = new Map<
    string,
    {
      controller: AbortController;
      promise: Promise<PageResponse>;
      value?: PageResponse;
    }
  >();

  constructor(
    protected readonly fetcher: Fetcher = (url, options) => fetch(url, options),
  ) {}

  prefetch(url: string): void {
    const previous = this.entries.get(url);
    if (previous && (!previous.value || previous.value.cacheUntil > Date.now()))
      return;
    this.entries.delete(url);
    while (this.entries.size >= 8) {
      const oldest = this.entries.keys().next().value;
      if (oldest) {
        this.entries.get(oldest)?.controller.abort();
        this.entries.delete(oldest);
      }
    }
    const controller = new AbortController();
    const entry: {
      controller: AbortController;
      promise: Promise<PageResponse>;
      value?: PageResponse;
    } = { controller, promise: this.fetch(url, controller.signal) };
    this.entries.set(url, entry);
    void entry.promise.then(
      (value) => {
        if (this.entries.get(url) !== entry) return;
        if (value.cacheUntil > Date.now()) entry.value = value;
        else this.entries.delete(url);
      },
      () => {
        if (this.entries.get(url) === entry) this.entries.delete(url);
      },
    );
  }

  async take(url: string, signal: AbortSignal): Promise<PageResponse> {
    const entry = this.entries.get(url);
    this.entries.delete(url);
    if (!entry || (entry.value && entry.value.cacheUntil <= Date.now()))
      return this.fetch(url, signal);
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
