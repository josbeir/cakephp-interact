import type { Fetcher } from '../types';
import { responseNonces } from './nonces';

interface PrefetchEntry {
  controller: AbortController;
  promise: Promise<PageResponse>;
  value?: PageResponse;
  attempt?: AbortSignal;
}

export interface PageResponse {
  html: string;
  url: string;
  cacheUntil: number;
  nonces: readonly string[];
}

/**
 * Holds bounded responses and the GET owned by an active hover, focus, or mouse press.
 * Completed responses belong only to that attempt; later reuse requires HTTP freshness.
 * Detached HTML never initializes Alpine or owns polling, upload, or lazy-load activity.
 */
export class PageFetcher {
  protected readonly entries = new Map<string, PrefetchEntry>();

  constructor(
    protected readonly fetcher: Fetcher = (url, options) => fetch(url, options),
  ) {}

  prefetch(url: string, attempt?: AbortSignal): void {
    if (attempt?.aborted) return;
    const previous = this.entries.get(url);
    if (
      previous &&
      (!previous.value ||
        previous.value.cacheUntil > Date.now() ||
        (previous.attempt &&
          !previous.attempt.aborted &&
          (!attempt || previous.attempt === attempt)))
    ) {
      if (attempt) this.hold(url, previous, attempt);
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
    if (attempt) this.hold(url, entry, attempt);
    void entry.promise.then(
      (value) => {
        if (this.entries.get(url) !== entry) return;
        entry.value = value;
        if (value.cacheUntil <= Date.now() && !entry.attempt)
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
    attempt?: AbortSignal,
  ): Promise<PageResponse> {
    const entry = this.entries.get(url);
    const owned =
      attempt !== undefined && !attempt.aborted && entry?.attempt === attempt;
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

  /** Keep early responses for their originating attempt and release abandoned work. */
  protected hold(
    url: string,
    entry: PrefetchEntry,
    attempt: AbortSignal,
  ): void {
    if (entry.attempt === attempt) return;
    entry.attempt = attempt;
    attempt.addEventListener(
      'abort',
      () => {
        if (this.entries.get(url) !== entry || entry.attempt !== attempt)
          return;
        delete entry.attempt;
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
      nonces: responseNonces(response.headers),
    };
  }
}
