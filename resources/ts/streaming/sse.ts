/**
 * Decodes standard SSE data messages across arbitrary UTF-8 and CR/LF chunk boundaries.
 *
 * Comments and protocol fields are accepted without reconnecting or replaying actions.
 * Incomplete trailing messages are discarded as required by SSE framing.
 */
export class SseDecoder {
  private buffer = '';
  private data: string[] = [];
  private event = '';
  private size = 0;
  private skipLf = false;
  private readonly decoder = new TextDecoder('utf-8', { fatal: true });

  constructor(
    private readonly receive: (event: string, data: string) => void,
  ) {}

  /** Consume bytes without assuming network chunks are complete characters or lines. */
  push(bytes: Uint8Array): void {
    this.consume(this.decoder.decode(bytes, { stream: true }));
  }

  /** Validate trailing UTF-8 without dispatching unterminated events. */
  finish(): void {
    this.consume(this.decoder.decode());
  }

  /** Split decoded characters into lines while preserving CRLF across byte chunks. */
  private consume(text: string): void {
    for (const character of text) {
      if (this.skipLf) {
        this.skipLf = false;
        if (character === '\n') continue;
      }
      if (character === '\r' || character === '\n') {
        const line = this.buffer;
        this.buffer = '';
        this.skipLf = character === '\r';
        this.line(line);
      } else {
        this.buffer += character;
        if (this.buffer.length > 1_048_576)
          throw new Error('Stream frame exceeds the size limit.');
      }
    }
  }

  /** Accumulate data fields and dispatch only at a complete blank-line boundary. */
  private line(line: string): void {
    if (line === '') {
      if (this.data.length)
        this.receive(this.event || 'message', this.data.join('\n'));
      this.data = [];
      this.event = '';
      this.size = 0;
      return;
    }
    const colon = line.indexOf(':');
    const field = colon < 0 ? line : line.slice(0, colon);
    let value = colon < 0 ? '' : line.slice(colon + 1);
    if (value.startsWith(' ')) value = value.slice(1);
    if (field === 'event') this.event = value;
    if (field === 'data') {
      this.data.push(value);
      if ((this.size += value.length + 1) > 1_048_576)
        throw new Error('Stream frame exceeds the size limit.');
    }
  }
}
