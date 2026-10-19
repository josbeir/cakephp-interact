import type { Fetcher, Invocation, IslandResponse, State } from '../types';
import type { MountMetadata } from './metadata';
import { response } from './protocol';

/**
 * Versioned browser request carrying a signed baseline and explicit writable edits.
 */
export type UpdatePayload = Invocation & {
  version: 1;
  snapshot: string;
  updates: State;
};

/**
 * Send one same-origin JSON request with CSRF protection and no automatic retry.
 *
 * Redirects are rejected so login documents cannot be mistaken for island markup.
 */
export function postUpdate(
  metadata: MountMetadata,
  payload: UpdatePayload,
  signal: AbortSignal,
  fetcher: Fetcher,
): Promise<Response> {
  return fetcher(metadata.endpoint, {
    method: 'POST',
    credentials: 'same-origin',
    redirect: 'error',
    cache: 'no-store',
    signal,
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      'X-CSRF-Token': metadata.csrf,
    },
    body: JSON.stringify(payload),
  });
}

/**
 * Validate a response before allowing its state or markup into the runtime.
 *
 * HTTP 422 is renderable validation feedback; other failed responses reject.
 *
 * @throws Error For a failed status, unexpected content type, or invalid identity/envelope.
 */
export async function readResponse(
  result: Response,
  id: string,
): Promise<IslandResponse> {
  if (
    (!result.ok && result.status !== 422) ||
    !result.headers.get('content-type')?.includes('application/json')
  )
    throw new Error(`Island request failed (${result.status}).`);
  const payload: unknown = await result.json();
  if (!response(payload) || payload.id !== id)
    throw new Error('Invalid island response.');
  return payload;
}
