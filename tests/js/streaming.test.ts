import { afterEach, expect, it, vi } from 'vite-plus/test';
import { mountClient, destroyBindings } from './mount';
import type { IslandClient } from '../../resources/ts/client';
import type { Fetcher } from '../../resources/ts/types';
import { SseDecoder } from '../../resources/ts/streaming/sse';
import { StreamReceiver } from '../../resources/ts/streaming/receiver';
import { readResponse } from '../../resources/ts/transport/request';
import { streamMessage } from '../../resources/ts/streaming/messages';

const clients: IslandClient[] = [];
const encoder = new TextEncoder();
function message(content = 'Hello', extra: Record<string, unknown> = {}) {
  return {
    version: 1,
    id: 'streaming',
    type: 'target',
    to: 'answer',
    content,
    replace: false,
    html: false,
    ...extra,
  };
}
function result(extra: Record<string, unknown> = {}) {
  return {
    version: 1,
    id: 'streaming',
    type: 'result',
    status: 200,
    payload: {
      version: 1,
      id: 'streaming',
      html: null,
      snapshot: 'fresh',
      state: { answer: 'Complete' },
      writable: { answer: { type: 'string', nullable: false } },
      errors: {},
      events: [],
      ...extra,
    },
  };
}
function frame(value: unknown): string {
  return `event: interact\ndata: ${JSON.stringify(value)}\n\n`;
}
function response(text: string): Response {
  return new Response(text, {
    headers: { 'Content-Type': 'text/event-stream' },
  });
}
function setup(
  fetcher: Fetcher,
  markup = '<pre x-interact:stream="answer"></pre><div x-interact:stream.html="results"></div>',
) {
  const root = document.createElement('section');
  Object.assign(root.dataset, {
    interactId: 'streaming',
    interactState: '{"answer":""}',
    interactWritable: '{"answer":{"type":"string","nullable":false}}',
    interactSnapshot: 'initial',
    interactEndpoint: '/update',
  });
  root.innerHTML = markup;
  document.body.append(root);
  const client = mountClient(root, fetcher);
  clients.push(client);
  return { root, client };
}
afterEach(() => {
  for (const client of clients.splice(0)) {
    destroyBindings(client.root);
    client.dispose();
  }
  document.body.replaceChildren();
  vi.restoreAllMocks();
});

it('shows chunks before completion, keeps the queue busy, and accepts the final signed result', async () => {
  let stream: ReadableStreamDefaultController<Uint8Array> | undefined;
  const fetcher = vi
    .fn<Fetcher>()
    .mockImplementationOnce(
      async () =>
        new Response(
          new ReadableStream<Uint8Array>({
            start(controller) {
              stream = controller;
            },
          }),
          { headers: { 'Content-Type': 'text/event-stream' } },
        ),
    )
    .mockImplementationOnce(async () => response(frame(result())));
  const { client, root } = setup(fetcher);
  const progress = vi.fn();
  root.addEventListener('interact:stream', progress);
  const first = client.bridge.call('generate');
  const second = client.bridge.call('retain');
  await vi.waitFor(() => expect(stream).toBeDefined());
  stream?.enqueue(encoder.encode(frame(message('<script>literal</script> €'))));
  stream?.enqueue(
    encoder.encode(
      frame({
        version: 1,
        id: 'streaming',
        type: 'event',
        name: 'progress',
        detail: { percent: 50 },
      }),
    ),
  );
  await vi.waitFor(() =>
    expect(root.querySelector('pre')?.textContent).toBe(
      '<script>literal</script> €',
    ),
  );
  expect(root.querySelector('script')).toBeNull();
  expect(progress).toHaveBeenCalledOnce();
  expect(client.bridge.busy).toBe(true);
  expect(client.bridge.pending('generate')).toBe(true);
  expect(client.bridge.state.answer).toBe('');
  expect(fetcher).toHaveBeenCalledOnce();
  stream?.enqueue(encoder.encode(frame(result())));
  stream?.close();
  await expect(first).resolves.toEqual({ ok: true });
  await expect(second).resolves.toEqual({ ok: true });
  expect(client.bridge.state.answer).toBe('Complete');
  expect(client.bridge.busy).toBe(false);
  const body = fetcher.mock.calls[1]?.[1].body;
  expect(typeof body === 'string' ? JSON.parse(body) : null).toMatchObject({
    snapshot: 'fresh',
    action: 'retain',
  });
});

it('allows transport-independent adapters to append, replace, scope targets, and initialize HTML once', async () => {
  const { root, client } = setup(
    async () => response(frame(result())),
    '<pre x-interact:stream="answer">Old</pre><pre x-interact:stream="answer"></pre><div x-interact:stream.html="results"></div><div data-interact-id="child"><pre>Child</pre></div>',
  );
  client.bridge.receiveStream(message('New', { replace: true }));
  expect(
    [...root.querySelectorAll('pre')].map((node) => node.textContent),
  ).toEqual(['New', 'New', 'Child']);
  client.bridge.receiveStream(message('Ignored', { to: 'missing' }));
  client.bridge.receiveStream(
    message(
      '<span x-data="{ count: 0 }" x-init="count++" x-text="count"></span>',
      { to: 'results', html: true },
    ),
  );
  await vi.waitFor(() =>
    expect(root.querySelector('span')?.textContent).toBe('1'),
  );
  client.bridge.receiveStream(
    message('<b>Replacement</b>', { to: 'results', html: true, replace: true }),
  );
  expect(root.querySelector('span')).toBeNull();
  expect(root.querySelector('b')?.textContent).toBe('Replacement');
  const target = root.querySelector('pre');
  target?.remove();
  client.bridge.receiveStream(message('!'));
  expect(target?.textContent).toBe('New');
  client.dispose();
  client.bridge.receiveStream({ invalid: true });
  expect(root.querySelector('pre')?.textContent).toBe('New!');
});

it.each([
  '<script>alert(1)</script>',
  '<input x-interact:model="answer">',
  '<div data-interact-id="child"></div>',
  '<div x-interact:ignore></div>',
  '<div x-modelable="value"></div>',
  '<button>Click</button>',
  '<div onclick="alert(1)"></div>',
  '<template><input></template>',
  '<div data-interact-state="{}"></div>',
  '<div x-interact:stream="nested"></div>',
  '<div contenteditable></div>',
])('rejects unsafe HTML ownership before changing any target: %s', (html) => {
  const { client, root } = setup(async () => response(frame(result())));
  expect(() =>
    client.bridge.receiveStream(message(html, { to: 'results', html: true })),
  ).toThrow();
  expect(root.children[1]?.childNodes.length).toBe(0);
});

it('rechecks target safety after local changes and rejects mismatched content modes', () => {
  const { client, root } = setup(async () => response(frame(result())));
  expect(() =>
    client.bridge.receiveStream(message('<b>bad</b>', { html: true })),
  ).toThrow('mode');
  root.querySelector('pre')?.append(document.createElement('input'));
  expect(() =>
    client.bridge.receiveStream(message('unsafe', { replace: true })),
  ).toThrow('display-only');
  expect(root.querySelector('input')).not.toBeNull();
});

it.each([
  ['Bad name', [], '<pre></pre>'],
  ['answer', ['unknown'], '<pre></pre>'],
  ['answer', ['html', 'html'], '<pre></pre>'],
  ['answer', [], '<input>'],
  ['answer', [], '<div x-interact:ignore><pre></pre></div>'],
  ['answer', [], '<div x-modelable="value"><pre></pre></div>'],
] as const)(
  'rejects invalid target declarations: %s %j %s',
  (name, modifiers, markup) => {
    const root = document.createElement('div');
    root.innerHTML = markup;
    const target = root.querySelector('pre, input');
    if (!(target instanceof HTMLElement)) throw new Error('Missing target');
    expect(() =>
      new StreamReceiver(root, () => true).bind(
        target,
        name,
        [...modifiers],
        'x-',
        () => {},
      ),
    ).toThrow();
  },
);

it('rejects root targets and mismatched identities without leaking state capabilities', () => {
  const { client, root } = setup(async () => response(frame(result())));
  expect(() =>
    client.streams.bind(root, 'answer', [], 'x-', () => {}),
  ).toThrow();
  expect(() =>
    client.bridge.receiveStream(message('Other', { id: 'child' })),
  ).toThrow();
  expect(() => client.bridge.receiveStream(result())).toThrow();
  expect(
    streamMessage(
      message('', {
        type: 'event',
        name: 'progress',
        detail: { constructor: 'unsafe' },
      }),
    ),
  ).toBe(false);
  expect(streamMessage(null)).toBe(false);
});

it('decodes split UTF-8, multiline data, comments, optional fields, CRLF, CR, and default event types', () => {
  const messages: [string, string][] = [];
  const decoder = new SseDecoder((event, data) => messages.push([event, data]));
  const bytes = encoder.encode(
    '\uFEFF: keepalive\r\nid: 1\r\nretry: 500\r\nevent: interact\r\ndata: €\r\ndata: second\r\n\r\ndata\r\rdata: unterminated',
  );
  for (const byte of bytes) decoder.push(Uint8Array.of(byte));
  decoder.finish();
  expect(messages).toEqual([
    ['interact', '€\nsecond'],
    ['message', ''],
  ]);
});

it('rejects oversized lines, oversized multiline frames, and invalid UTF-8', () => {
  expect(() =>
    new SseDecoder(() => {}).push(encoder.encode('x'.repeat(1_048_577))),
  ).toThrow('size');
  expect(() =>
    new SseDecoder(() => {}).push(
      encoder.encode(
        `data: ${'x'.repeat(600_000)}\ndata: ${'x'.repeat(600_000)}\n`,
      ),
    ),
  ).toThrow('size');
  expect(() => new SseDecoder(() => {}).push(Uint8Array.of(255))).toThrow();
  const decoder = new SseDecoder(() => {});
  decoder.push(Uint8Array.of(0xe2));
  expect(() => decoder.finish()).toThrow();
});

it.each([
  '',
  frame(message('partial')),
  frame(result()) + frame(result()),
  frame(result()) + frame(message()),
  'event: other\ndata: {}\n\n',
  'event: interact\ndata: not json\n\n',
  frame({ ...result(), id: 'other' }),
  frame({ ...result(), status: 422 }),
  frame({ ...result(), status: 500 }),
  frame({ ...result(), type: 'unknown' }),
])(
  'fails closed for malformed or truncated streams without retrying actions: %j',
  async (text) => {
    const fetcher = vi.fn<Fetcher>(async () => response(text));
    const { client } = setup(fetcher);
    await expect(client.bridge.call('generate')).resolves.toMatchObject({
      ok: false,
      error: { code: 'invalid_response' },
    });
    expect(client.bridge.state.answer).toBe('');
    expect(fetcher).toHaveBeenCalledOnce();
    expect(client.bridge.paused).toBe(true);
  },
);

it('leaves partial output visible on safe server failure and does not deliver final effects', async () => {
  const fetcher = vi.fn<Fetcher>(async () =>
    response(
      frame(message('Partial')) +
        frame({
          version: 1,
          id: 'streaming',
          type: 'failure',
          status: 500,
          payload: {
            version: 1,
            error: { code: 'server_error', message: 'Safe failure' },
          },
        }),
    ),
  );
  const { client, root } = setup(fetcher);
  await expect(client.bridge.call('generate')).resolves.toMatchObject({
    ok: false,
    error: { code: 'server_error', status: 500 },
  });
  expect(root.querySelector('pre')?.textContent).toBe('Partial');
  expect(client.bridge.state.answer).toBe('');
  expect(fetcher).toHaveBeenCalledOnce();
});

it('accepts validation terminal markup through the normal renderer and keeps newer drafts', async () => {
  let stream: ReadableStreamDefaultController<Uint8Array> | undefined;
  const { root, client } = setup(
    async () =>
      new Response(
        new ReadableStream<Uint8Array>({
          start(controller) {
            stream = controller;
          },
        }),
        { headers: { 'Content-Type': 'text/event-stream' } },
      ),
    '<input aria-label="Answer" x-interact:model="answer"><pre x-interact:stream="answer"></pre>',
  );
  const action = client.bridge.call('generate');
  await vi.waitFor(() => expect(stream).toBeDefined());
  client.bridge.state.answer = 'newer';
  stream?.enqueue(
    encoder.encode(
      frame({
        ...result({
          html: '<section data-interact-id="streaming"><input aria-label="Answer" x-interact:model="answer"><p>Invalid</p></section>',
          errors: { answer: ['Invalid'] },
        }),
        status: 422,
      }),
    ),
  );
  stream?.close();
  await expect(action).resolves.toEqual({ ok: true });
  expect(client.bridge.state.answer).toBe('newer');
  expect(root.querySelector('input')?.value).toBe('newer');
  expect(root.querySelector('p')?.textContent).toBe('Invalid');
  expect(client.bridge.errors.answer).toEqual(['Invalid']);
});

it('ignores late chunks after disposal and rejects unsolicited synchronization streams', async () => {
  let stream: ReadableStreamDefaultController<Uint8Array> | undefined;
  const { client, root } = setup(
    async () =>
      new Response(
        new ReadableStream<Uint8Array>({
          start(controller) {
            stream = controller;
          },
        }),
        { headers: { 'Content-Type': 'text/event-stream' } },
      ),
  );
  const action = client.bridge.call('generate');
  await vi.waitFor(() => expect(stream).toBeDefined());
  client.dispose();
  stream?.enqueue(encoder.encode(frame(message('Late')) + frame(result())));
  stream?.close();
  await expect(action).resolves.toMatchObject({
    ok: false,
    error: { code: 'cancelled' },
  });
  expect(root.querySelector('pre')?.textContent).toBe('');
  const other = setup(async () => response(frame(result())));
  await expect(other.client.bridge.sync()).resolves.toMatchObject({
    ok: false,
    error: { code: 'invalid_response' },
  });
});

it('requires a reader, HTTP 200, and a streaming receiver', async () => {
  await expect(
    readResponse(response(frame(result())), 'streaming'),
  ).rejects.toMatchObject({ failure: { code: 'invalid_response' } });
  await expect(
    readResponse(
      new Response(null, { headers: { 'Content-Type': 'text/event-stream' } }),
      'streaming',
      () => {},
    ),
  ).rejects.toMatchObject({ failure: { code: 'invalid_response' } });
  await expect(
    readResponse(
      new Response('', {
        status: 422,
        headers: { 'Content-Type': 'text/event-stream' },
      }),
      'streaming',
      () => {},
    ),
  ).rejects.toMatchObject({ failure: { code: 'invalid_response' } });
});

it('reuses verified target structure while appending many chunks and invalidates on external changes', async () => {
  const { client, root } = setup(async () => response(frame(result())));
  const text = root.querySelector('pre');
  const html = root.children[1];
  if (!text || !(html instanceof HTMLElement))
    throw new Error('Missing target');
  const textScan = vi.spyOn(text, 'querySelectorAll');
  const htmlScan = vi.spyOn(html, 'querySelectorAll');
  for (let index = 0; index < 100; index++) {
    client.bridge.receiveStream(message('a'));
    client.bridge.receiveStream(
      message(`<p>${index}</p>`, { html: true, to: 'results' }),
    );
  }
  expect(text.textContent).toBe('a'.repeat(100));
  expect(text.childNodes).toHaveLength(1);
  expect(html.children).toHaveLength(100);
  expect(textScan).not.toHaveBeenCalled();
  expect(htmlScan).not.toHaveBeenCalled();
  text.append(document.createElement('input'));
  await Promise.resolve();
  expect(() => client.bridge.receiveStream(message('unsafe'))).toThrow(
    'display-only',
  );
  expect(textScan).toHaveBeenCalledOnce();
  expect(() =>
    client.streams.bind(text, 'answer', [], 'x-', () => {}),
  ).toThrow();
});

it('rechecks changes made by the host lifecycle operation before a later update', async () => {
  const root = document.createElement('div');
  root.dataset.interactId = 'streaming';
  const target = document.createElement('div');
  root.append(target);
  const receiver = new StreamReceiver(root, () => true);
  receiver.bind(target, 'results', ['html'], 'x-', (fragment) => {
    target.append(fragment);
    target.firstElementChild?.append(document.createElement('input'));
  });
  receiver.receive(message('<p>Content</p>', { html: true, to: 'results' }));
  await Promise.resolve();
  expect(() =>
    receiver.receive(message('<p>Next</p>', { html: true, to: 'results' })),
  ).toThrow('display-only');
  receiver.dispose();
});

it.each([
  {
    status: 500.5,
    payload: {
      version: 1,
      error: { code: 'server_error', message: 'Invalid status' },
    },
  },
  { status: 500, payload: null },
  {
    status: 500,
    payload: { version: 1, error: { code: 'server_error', message: '' } },
  },
])('rejects malformed terminal failure frames: %j', async (extra) => {
  const { client } = setup(async () =>
    response(frame({ version: 1, id: 'streaming', type: 'failure', ...extra })),
  );
  await expect(client.bridge.call('generate')).resolves.toMatchObject({
    ok: false,
    error: { code: 'invalid_response' },
  });
});
