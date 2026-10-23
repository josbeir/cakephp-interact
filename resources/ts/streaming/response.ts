import type { UpdateResponse } from '../types';
import { record } from '../state/values';
import {
  requestError,
  readFailure,
  IslandRequestError,
} from '../transport/failure';
import { response } from '../transport/protocol';
import { streamMessage } from './messages';
import { SseDecoder } from './sse';

/** Read a finite action response, retaining the request queue until exactly one terminal frame. */
export async function readStream(
  result: Response,
  id: string,
  receive: (message: unknown) => void,
): Promise<UpdateResponse> {
  if (result.status !== 200 || !result.body)
    throw requestError('invalid_response', result.status);
  const reader = result.body.getReader();
  let terminal: UpdateResponse | IslandRequestError | undefined;
  const decoder = new SseDecoder((event, data) => {
    if (event !== 'interact' || terminal)
      throw requestError('invalid_response', 200);
    const message: unknown = JSON.parse(data);
    if (!record(message) || message.version !== 1 || message.id !== id)
      throw requestError('invalid_response', 200);
    if (streamMessage(message)) {
      receive(message);
      return;
    }
    if (
      message.type === 'result' &&
      (message.status === 200 || message.status === 422) &&
      response(message.payload) &&
      message.payload.id === id &&
      !(
        ('redirect' in message.payload || message.payload.html === null) &&
        message.status !== 200
      )
    ) {
      terminal = message.payload;
    } else if (
      message.type === 'failure' &&
      typeof message.status === 'number' &&
      Number.isInteger(message.status) &&
      message.status >= 400 &&
      message.status <= 599 &&
      record(message.payload) &&
      message.payload.version === 1 &&
      record(message.payload.error) &&
      typeof message.payload.error.code === 'string' &&
      typeof message.payload.error.message === 'string' &&
      message.payload.error.message.length > 0
    ) {
      // Resolve the shared safe failure vocabulary after the decoder has consumed the frame.
      terminal = requestError('http_error', message.status, message.payload);
      failure = { status: message.status, payload: message.payload };
    } else throw requestError('invalid_response', 200);
  });
  let failure: { status: number; payload: unknown } | undefined;
  try {
    while (true) {
      const chunk = await reader.read();
      if (chunk.done) break;
      decoder.push(chunk.value);
    }
    decoder.finish();
    if (failure)
      throw await readFailure(
        new Response(JSON.stringify(failure.payload), {
          status: failure.status,
          headers: { 'Content-Type': 'application/json' },
        }),
      );
    if (!terminal || terminal instanceof IslandRequestError)
      throw requestError('invalid_response', 200);
    return terminal;
  } catch (cause: unknown) {
    await reader.cancel().catch(() => {});
    if (cause instanceof IslandRequestError) throw cause;
    throw requestError('invalid_response', 200, cause);
  } finally {
    reader.releaseLock();
  }
}
