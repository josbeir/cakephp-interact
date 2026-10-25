import { record } from '../state/values';
import { SseDecoder } from '../streaming/sse';
import { topic } from './protocol';
import type { RealtimeTransport } from './types';

/** Consume Mercure 1.x with tab-local bearer credentials and explicit connection cleanup. */
export const mercureTransport: RealtimeTransport = (grant, callbacks) => {
  const data = grant.data;
  if (
    typeof data.url !== 'string' ||
    typeof data.token !== 'string' ||
    !data.token ||
    !Array.isArray(data.topics) ||
    !data.topics.every(topic)
  )
    throw new Error('Invalid Mercure connection data.');
  const url = new URL(data.url, window.location.href);
  if (
    !['http:', 'https:'].includes(url.protocol) ||
    url.username ||
    url.password
  )
    throw new Error('Invalid Mercure hub URL.');
  url.searchParams.delete('match');
  for (const value of data.topics) url.searchParams.append('match', value);
  const token = data.token;
  const lifetime = new AbortController();
  let cursor: string | undefined;

  async function connect(): Promise<void> {
    let failures = 0;
    while (!lifetime.signal.aborted) {
      try {
        if (cursor !== undefined) url.searchParams.set('last_event_id', cursor);
        const response = await fetch(url, {
          headers: {
            Accept: 'text/event-stream',
            Authorization: `Bearer ${token}`,
          },
          credentials: 'omit',
          cache: 'no-store',
          redirect: 'error',
          signal: lifetime.signal,
        });
        if (!response.ok) {
          if (response.status < 500)
            throw new Error('Mercure authorization failed.');
          throw new TypeError('Mercure unavailable.');
        }
        if (
          !response.headers
            .get('content-type')
            ?.includes('text/event-stream') ||
          !response.body
        )
          throw new Error('Invalid Mercure response.');
        callbacks.status('connected');
        failures = 0;
        const decoder = new SseDecoder((_event, json, id) => {
          const message: unknown = JSON.parse(json);
          if (!record(message)) throw new Error('Invalid Mercure message.');
          callbacks.receive(message);
          if (id !== undefined) cursor = id;
        });
        const reader = response.body.getReader();
        try {
          while (!lifetime.signal.aborted) {
            const part = await reader.read();
            if (part.done) break;
            decoder.push(part.value);
          }
          decoder.finish();
        } finally {
          await reader.cancel().catch(() => {});
          reader.releaseLock();
        }
        if (!lifetime.signal.aborted)
          throw new TypeError('Mercure disconnected.');
      } catch (cause: unknown) {
        if (lifetime.signal.aborted) return;
        if (!(cause instanceof TypeError)) {
          callbacks.status('error', 'Realtime connection failed.');
          return;
        }
        callbacks.status('reconnecting');
        const delay =
          Math.min(30_000, 500 * 2 ** Math.min(failures++, 6)) *
          (0.75 + Math.random() * 0.5);
        await new Promise<void>((resolve) => {
          const abort = () => {
            clearTimeout(timer);
            resolve();
          };
          const timer = setTimeout(() => {
            lifetime.signal.removeEventListener('abort', abort);
            resolve();
          }, delay);
          lifetime.signal.addEventListener('abort', abort, { once: true });
        });
      }
    }
  }

  void connect();
  return () => lifetime.abort();
};
