import { readFileSync } from 'node:fs';
import { runInThisContext } from 'node:vm';
import { afterEach, beforeEach, expect, it, vi } from 'vite-plus/test';
import type {
  RealtimeGrant,
  RealtimeTransport,
} from '../../resources/ts/realtime/types';

/**
 * Controls the documented WebSocket boundary without replacing adapter lifecycle behavior.
 *
 * Native events exercise acknowledgement, reconnects, and deterministic listener cleanup.
 */
class ExampleSocket extends EventTarget {
  static readonly connections: ExampleSocket[] = [];
  readonly send = vi.fn();
  readonly close = vi.fn();

  constructor(readonly url: URL) {
    super();
    ExampleSocket.connections.push(this);
  }

  /** Deliver a server frame through the documented adapter's actual listener. */
  frame(value: string): void {
    this.dispatchEvent(new MessageEvent('message', { data: value }));
  }
}

runInThisContext(
  readFileSync('tests/Fixture/browser/websocket-feed.js', 'utf8'),
);
const disposers: (() => void)[] = [];
const grant: RealtimeGrant = {
  driver: 'websocket',
  data: { url: 'wss://feed.test/', token: 'subscriber', topics: ['records/1'] },
  expires: 9999999999,
};

beforeEach(() => {
  vi.useFakeTimers();
  vi.spyOn(Math, 'random').mockReturnValue(0.5);
  vi.stubGlobal('WebSocket', ExampleSocket);
});
afterEach(() => {
  disposers.splice(0).forEach((dispose) => dispose());
  ExampleSocket.connections.length = 0;
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
  vi.useRealTimers();
});

function adapter(): RealtimeTransport {
  let transport: RealtimeTransport | undefined;
  document.dispatchEvent(
    new CustomEvent('interact:configure', {
      detail: {
        registerTransport: (name: string, value: RealtimeTransport) => {
          expect(name).toBe('websocket');
          transport = value;
        },
      },
    }),
  );
  if (!transport) throw new Error('Missing example transport registration.');
  return transport;
}

it('authenticates before receiving messages and removes listeners on disposal', () => {
  const callbacks = { receive: vi.fn(), status: vi.fn() };
  const dispose = adapter()(grant, callbacks);
  disposers.push(dispose);
  const socket = ExampleSocket.connections[0];
  if (!socket) throw new Error('Missing socket.');
  socket.dispatchEvent(new Event('open'));
  expect(JSON.parse(String(socket.send.mock.calls[0]?.[0]))).toEqual({
    type: 'subscribe',
    token: 'subscriber',
    topics: ['records/1'],
  });
  socket.frame('{"type":"subscribed"}');
  expect(callbacks.status).toHaveBeenCalledWith('connected');
  socket.frame('{"version":1,"topic":"records/1"}');
  expect(callbacks.receive).toHaveBeenCalledWith({
    version: 1,
    topic: 'records/1',
  });
  dispose();
  socket.frame('{"version":1}');
  socket.dispatchEvent(new Event('close'));
  expect(callbacks.receive).toHaveBeenCalledOnce();
  expect(socket.close).toHaveBeenCalledOnce();
  expect(vi.getTimerCount()).toBe(0);
});

it.each(['invalid JSON', 'null', '{"version":1}'])(
  'rejects invalid or unacknowledged frames: %s',
  (frame) => {
    const callbacks = { receive: vi.fn(), status: vi.fn() };
    disposers.push(adapter()(grant, callbacks));
    const socket = ExampleSocket.connections[0];
    socket?.frame(frame);
    expect(callbacks.receive).not.toHaveBeenCalled();
    expect(callbacks.status).toHaveBeenCalledWith(
      'error',
      'WebSocket message rejected.',
    );
    expect(socket?.close).toHaveBeenCalledOnce();
  },
);

it('reconnects after closure and cancels pending retries on disposal', async () => {
  const callbacks = { receive: vi.fn(), status: vi.fn() };
  const dispose = adapter()(grant, callbacks);
  disposers.push(dispose);
  ExampleSocket.connections[0]?.dispatchEvent(new Event('close'));
  expect(callbacks.status).toHaveBeenCalledWith('reconnecting');
  await vi.advanceTimersByTimeAsync(500);
  expect(ExampleSocket.connections).toHaveLength(2);
  ExampleSocket.connections[1]?.dispatchEvent(new Event('close'));
  dispose();
  await vi.advanceTimersByTimeAsync(30000);
  expect(ExampleSocket.connections).toHaveLength(2);
  expect(() =>
    adapter()({ ...grant, data: { url: 'https://invalid.test/' } }, callbacks),
  ).toThrow('Invalid WebSocket');
});
