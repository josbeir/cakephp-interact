import type { UpdateResponse } from '../types';
import { record } from '../state/values';
import {
  requestError,
  failureEnvelope,
  failurePayload,
  IslandRequestError,
} from '../transport/failure';
import { responseMatches } from '../transport/protocol';
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
      responseMatches(message.payload, id, message.status)
    ) {
      terminal = message.payload;
    } else if (
      message.type === 'failure' &&
      typeof message.status === 'number' &&
      Number.isInteger(message.status) &&
      message.status >= 400 &&
      message.status <= 599 &&
      failureEnvelope(message.payload)
    ) {
      terminal = failurePayload(message.payload, message.status);
    } else throw requestError('invalid_response', 200);
  });
  try {
    while (true) {
      const chunk = await reader.read();
      if (chunk.done) break;
      decoder.push(chunk.value);
    }
    decoder.finish();
    if (terminal instanceof IslandRequestError) throw terminal;
    if (!terminal) throw requestError('invalid_response', 200);
    return terminal;
  } catch (cause: unknown) {
    await reader.cancel().catch(() => {});
    if (cause instanceof IslandRequestError) throw cause;
    throw requestError('invalid_response', 200, cause);
  } finally {
    reader.releaseLock();
  }
}
