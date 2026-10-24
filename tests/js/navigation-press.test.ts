import { afterEach, beforeEach, expect, it, vi } from 'vite-plus/test';
import { PageFetcher } from '../../resources/ts/navigation/prefetch';
import type { Fetcher } from '../../resources/ts/types';

const url = new URL('/pressed', window.location.href).href;
function response(body = 'Original page', policy = 'no-store'): Response {
  return new Response(body, {
    headers: { 'Content-Type': 'text/html', 'Cache-Control': policy },
  });
}
beforeEach(() => {
  vi.useFakeTimers();
});
afterEach(() => {
  vi.useRealTimers();
});

it('reserves early uncached HTML for its own click and consumes it only once', async () => {
  const fetcher = vi.fn<Fetcher>(async (): Promise<Response> =>
    response('Page ' + fetcher.mock.calls.length),
  );
  const pages = new PageFetcher(fetcher);
  const press = new AbortController();
  pages.prefetch(url, press.signal);
  await vi.runAllTimersAsync();
  // A distinct navigation cannot take another gesture's uncached result.
  expect((await pages.take(url, new AbortController().signal)).html).toBe(
    'Page 2',
  );
  expect(
    (await pages.take(url, new AbortController().signal, press.signal)).html,
  ).toBe('Page 1');
  press.abort();
  expect((await pages.take(url, new AbortController().signal)).html).toBe(
    'Page 3',
  );
  expect(fetcher).toHaveBeenCalledTimes(3);
});

it('promotes an in-flight hover request without letting later focus discard the pressed response', async () => {
  let complete: ((response: Response) => void) | undefined;
  const fetcher = vi.fn<Fetcher>(
    () =>
      new Promise((resolve) => {
        complete = resolve;
      }),
  );
  const pages = new PageFetcher(fetcher);
  pages.prefetch(url);
  const press = new AbortController();
  pages.prefetch(url, press.signal);
  pages.prefetch(url, press.signal);
  complete?.(response());
  await vi.runAllTimersAsync();
  pages.prefetch(url);
  expect(
    (await pages.take(url, new AbortController().signal, press.signal)).html,
  ).toBe('Original page');
  press.abort();
  expect(fetcher).toHaveBeenCalledOnce();
});

it.each(['no-store', 'no-cache', 'private, max-age=30'])(
  'releases a completed press according to %s for later visits',
  async (policy) => {
    const fetcher = vi.fn<Fetcher>(async () => response('Page', policy));
    const pages = new PageFetcher(fetcher);
    const press = new AbortController();
    pages.prefetch(url, press.signal);
    await vi.runAllTimersAsync();
    press.abort();
    await pages.take(url, new AbortController().signal);
    expect(fetcher).toHaveBeenCalledTimes(policy.includes('max-age') ? 1 : 2);
  },
);

it('aborts an abandoned pending press without leaving a rejected request unhandled', async () => {
  const fetcher = vi.fn<Fetcher>(
    async (_url, options) =>
      new Promise((_resolve, reject) => {
        options.signal?.addEventListener('abort', () =>
          reject(new DOMException('Canceled', 'AbortError')),
        );
      }),
  );
  const pages = new PageFetcher(fetcher);
  const press = new AbortController();
  pages.prefetch(url, press.signal);
  press.abort();
  await vi.runAllTimersAsync();
  expect(fetcher.mock.calls[0]?.[1].signal?.aborted).toBe(true);
  fetcher.mockResolvedValueOnce(response('Fresh page'));
  expect((await pages.take(url, new AbortController().signal)).html).toBe(
    'Fresh page',
  );
});

it('does not reuse an old press for a new gesture, including after old cleanup runs', async () => {
  const fetcher = vi.fn<Fetcher>(async (): Promise<Response> =>
    response('Page ' + fetcher.mock.calls.length),
  );
  const pages = new PageFetcher(fetcher);
  const first = new AbortController();
  pages.prefetch(url, first.signal);
  await vi.runAllTimersAsync();
  const second = new AbortController();
  pages.prefetch(url, second.signal);
  first.abort();
  await vi.runAllTimersAsync();
  expect(
    (await pages.take(url, new AbortController().signal, second.signal)).html,
  ).toBe('Page 2');
  second.abort();
  expect(fetcher).toHaveBeenCalledTimes(2);
});

it('invalidates held responses and ignores already abandoned presses', async () => {
  const fetcher = vi.fn<Fetcher>(async () => response());
  const pages = new PageFetcher(fetcher);
  const canceled = new AbortController();
  canceled.abort();
  pages.prefetch(url, canceled.signal);
  expect(fetcher).not.toHaveBeenCalled();
  const press = new AbortController();
  pages.prefetch(url, press.signal);
  await vi.runAllTimersAsync();
  pages.clear();
  press.abort();
  await pages.take(url, new AbortController().signal);
  expect(fetcher).toHaveBeenCalledTimes(2);
});
