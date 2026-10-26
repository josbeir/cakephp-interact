import { readFileSync } from 'node:fs';
import { runInThisContext } from 'node:vm';
import Alpine from '@alpinejs/csp';
import { afterEach, expect, it, vi } from 'vite-plus/test';
import { mountClient, destroyBindings } from '../../support/mount';
import type { IslandClient } from '../../../../resources/ts/client';

/**
 * Controls the external feed used by the documented application adapter.
 *
 * Connections expose only the EventSource API consumed by the example, keeping
 * protocol validation and DOM delivery on the real Interact receiver.
 */
class ExampleFeed {
  static readonly connections: ExampleFeed[] = [];
  onmessage: ((event: MessageEvent<string>) => void) | undefined;
  readonly close = vi.fn();

  constructor(readonly url: string) {
    ExampleFeed.connections.push(this);
  }

  /** Deliver a provider payload through the example's actual message callback. */
  send(data: string): void {
    this.onmessage?.(new MessageEvent('message', { data }));
  }
}

const clients: IslandClient[] = [];
afterEach(() => {
  for (const client of clients.splice(0)) {
    destroyBindings(client.root);
    client.dispose();
  }
  ExampleFeed.connections.length = 0;
  document.body.replaceChildren();
  delete window.Alpine;
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

it('connects the documented adapter, scopes text updates, rejects invalid data, and closes on disposal', () => {
  window.Alpine = Alpine;
  vi.stubGlobal('EventSource', ExampleFeed);
  runInThisContext(
    readFileSync('tests/Fixture/Streaming/Providers/text-feed.js', 'utf8'),
    {
      filename: 'text-feed.js',
    },
  );
  document.dispatchEvent(new Event('alpine:init'));
  const fetcher = vi.fn(async () => {
    throw new Error('The receiving adapter must not send an action request.');
  });
  for (const id of ['first', 'second']) {
    const root = document.createElement('section');
    Object.assign(root.dataset, {
      interactId: id,
      interactState: '{}',
      interactWritable: '{}',
      interactSnapshot: 'signed',
      interactEndpoint: '/update',
    });
    root.innerHTML = `<div x-data="textFeed" data-feed-url="/feed/${id}"><pre x-interact:stream="output"></pre></div>`;
    document.body.append(root);
    clients.push(mountClient(root, fetcher));
  }
  const [first, second] = ExampleFeed.connections;
  if (!first || !second) throw new Error('Missing feed connections.');
  expect(first.url).toBe('/feed/first');
  expect(second.url).toBe('/feed/second');
  first.send(JSON.stringify({ text: '<script>literal text</script>' }));
  first.send(JSON.stringify({ text: '\nNext result' }));
  expect(clients[0]?.root.querySelector('pre')?.textContent).toBe(
    '<script>literal text</script>\nNext result',
  );
  expect(clients[1]?.root.querySelector('pre')?.textContent).toBe('');
  expect(document.querySelector('script')).toBeNull();
  second.send(JSON.stringify({ text: 'Other island' }));
  expect(clients[1]?.root.querySelector('pre')?.textContent).toBe(
    'Other island',
  );
  const error = vi.spyOn(window.console, 'error').mockImplementation(() => {});
  for (const data of ['invalid JSON', 'null', '{"text":42}']) first.send(data);
  expect(error).toHaveBeenCalledTimes(3);
  expect(clients[0]?.bridge.error).toBeNull();
  expect(clients[0]?.bridge.busy).toBe(false);
  expect(clients[0]?.bridge.state).toEqual({});
  const client = clients.shift();
  if (!client) throw new Error('Missing adapter island.');
  destroyBindings(client.root);
  client.dispose();
  first.send(JSON.stringify({ text: 'Late message' }));
  expect(first.close).toHaveBeenCalledOnce();
  expect(client.root.querySelector('pre')?.textContent).toBe(
    '<script>literal text</script>\nNext result',
  );
  expect(second.close).not.toHaveBeenCalled();
  expect(fetcher).not.toHaveBeenCalled();
});
