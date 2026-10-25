import { createParser } from 'eventsource-parser';
import type { EventSourceParser } from 'eventsource-parser';

const maxDataBytes = 1_048_576;

/**
 * Decodes bounded UTF-8 SSE messages without reconnecting or replaying actions.
 *
 * The shared PHP/browser limit applies to encoded event data. The parser owns
 * framing and optional fields; incomplete trailing messages are discarded.
 */
export class SseDecoder {
  private readonly decoder = new TextDecoder('utf-8', {
    fatal: true,
    ignoreBOM: true,
  });
  private readonly encoder = new TextEncoder();
  private readonly parser: EventSourceParser;

  constructor(receive: (event: string, data: string, id?: string) => void) {
    this.parser = createParser({
      // Allow the field prefix while an exactly-at-limit data line is incomplete.
      maxBufferSize: maxDataBytes + 'data: '.length,
      onEvent: ({ event, data, id }) => {
        if (this.encoder.encode(data).byteLength > maxDataBytes)
          throw new Error('Stream message exceeds the size limit.');
        if (id === undefined) receive(event || 'message', data);
        else receive(event || 'message', data, id);
      },
      onError(error) {
        if (error.type === 'max-buffer-size-exceeded') throw error;
      },
    });
  }

  /** Consume bytes without assuming network chunks are complete characters or messages. */
  push(bytes: Uint8Array): void {
    this.parser.feed(this.decoder.decode(bytes, { stream: true }));
  }

  /** Validate trailing UTF-8 and discard messages lacking a terminating blank line. */
  finish(): void {
    this.parser.feed(this.decoder.decode());
    this.parser.reset();
  }
}
