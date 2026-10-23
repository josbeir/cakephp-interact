import type { Fetcher, Invocation, UpdateResponse, State } from '../types';
import type { MountMetadata } from './metadata';
import { readStream } from '../streaming/response';
import { response } from './protocol';
import { readFailure, requestError } from './failure';

/**
 * Versioned browser request carrying a signed baseline and explicit writable edits.
 */
export type UpdatePayload = Pick<Invocation, 'action' | 'args'> & {
  initialize?: boolean;
  upload?: { kind: 'stage' | 'remove'; field: string; id?: string };
  version: 1;
  snapshot: string;
  updates: State;
  pageQuery?: string;
  urlRestore?: boolean;
  parentInputs?: string;
};

/**
 * Send one same-origin JSON request with CSRF protection and no automatic retry.
 *
 * Redirects are detected without following them or treating login documents as island markup.
 */
export async function postUpdate(
  metadata: MountMetadata,
  payload: UpdatePayload,
  signal: AbortSignal,
  fetcher: Fetcher,
): Promise<Response> {
  try {
    return await fetcher(metadata.endpoint, {
      method: 'POST',
      credentials: 'same-origin',
      redirect: 'manual',
      cache: 'no-store',
      signal,
      headers: {
        Interact: 'true',
        'Content-Type': 'application/json',
        Accept: 'application/json, text/event-stream',
        'X-CSRF-Token': metadata.csrf,
      },
      body: JSON.stringify(payload),
    });
  } catch (cause: unknown) {
    throw requestError('network_error', null, cause);
  }
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
  receive?: (message: unknown) => void,
): Promise<UpdateResponse> {
  if (
    result.type === 'opaqueredirect' ||
    (result.status >= 300 && result.status < 400)
  )
    throw requestError('unexpected_redirect', result.status || null);
  if (!result.ok && result.status !== 422) throw await readFailure(result);
  if (result.headers.get('content-type')?.includes('text/event-stream')) {
    if (!receive) throw requestError('invalid_response', result.status);
    return readStream(result, id, receive);
  }
  if (!result.headers.get('content-type')?.includes('application/json'))
    throw requestError('invalid_response', result.status);
  let payload: unknown;
  try {
    payload = await result.json();
  } catch (cause: unknown) {
    throw requestError('invalid_response', result.status, cause);
  }
  if (
    !response(payload) ||
    payload.id !== id ||
    (('redirect' in payload || payload.html === null) && result.status !== 200)
  )
    throw requestError('invalid_response', result.status);
  return payload;
}
