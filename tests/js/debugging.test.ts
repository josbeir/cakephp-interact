import { afterEach, describe, expect, it, vi } from 'vite-plus/test';
import {
  DiagnosticRequest,
  diagnosticEnvelope,
  diagnosticOutput,
} from '../../resources/ts/diagnostics/output';
import { readResponse } from '../../resources/ts/transport/request';
import { readFailure } from '../../resources/ts/transport/failure';

const output = (text = 'dump', viewer = true) => ({
  output: text,
  viewer,
  truncated: false,
});
const payload = (diagnostics: unknown = output()) => ({
  version: 1,
  id: 'debug',
  html: '<div>Updated</div>',
  snapshot: 'signed',
  state: {},
  writable: {},
  errors: {},
  events: [],
  diagnostics,
});
const json = (value: unknown, status = 200) =>
  new Response(JSON.stringify(value), {
    status,
    headers: { 'Content-Type': 'application/json' },
  });
function stream(messages: unknown[]): Response {
  return new Response(
    messages
      .map((message) => `event: interact\ndata: ${JSON.stringify(message)}\n\n`)
      .join(''),
    { headers: { 'Content-Type': 'text/event-stream' } },
  );
}
function viewer(): ShadowRoot {
  const root = document.querySelector('aside[aria-label="Island debug"]');
  if (!root?.shadowRoot) throw new Error('Missing debug viewer');
  return root.shadowRoot;
}
function loadedFrame(): HTMLIFrameElement {
  const result = viewer().querySelector('iframe');
  if (!result) throw new Error('Missing output frame');
  result.dispatchEvent(new Event('load'));
  return result;
}
function button(label: string): HTMLButtonElement {
  const result = Array.from(viewer().querySelectorAll('button')).find(
    (entry) => entry.getAttribute('aria-label') === label,
  );
  if (!result) throw new Error(`Missing button: ${label}`);
  return result;
}

afterEach(() => {
  document.dispatchEvent(new Event('interact:navigating'));
  document.body.replaceChildren();
});

describe('native island output', () => {
  it('validates bounded diagnostics independently of island state', () => {
    expect(diagnosticOutput(output())).toBe(true);
    expect(diagnosticEnvelope({})).toBe(true);
    expect(diagnosticEnvelope(null)).toBe(false);
    for (const value of [
      null,
      {},
      output().output,
      { ...output(), output: 1 },
      { ...output(), output: 'x'.repeat(786_433) },
      { ...output(), truncated: null },
      { ...output(), viewer: 1 },
    ]) {
      expect(diagnosticOutput(value)).toBe(false);
      expect(diagnosticEnvelope({ diagnostics: value })).toBe(false);
    }
  });

  it('applies normal JSON, validation and renderless responses with separate output', async () => {
    const input = document.createElement('input');
    document.body.append(input);
    input.focus();
    const result = await readResponse(json(payload()), 'debug');
    expect(result).toMatchObject({ state: {}, diagnostics: output() });
    expect(document.activeElement).toBe(input);
    expect(loadedFrame().srcdoc).toContain('<pre>dump</pre>');
    expect(loadedFrame().getAttribute('sandbox')).toBe('');
    expect(loadedFrame().srcdoc).toContain('default-src &#39;none&#39;');
    await readResponse(
      json(
        { ...payload(output('validation')), errors: { value: ['Invalid'] } },
        422,
      ),
      'debug',
    );
    expect(loadedFrame().srcdoc).toContain('validation');
    await readResponse(
      json({ ...payload(output('renderless')), html: null }),
      'debug',
    );
    expect(loadedFrame().srcdoc).toContain('renderless');
    await readResponse(
      json({ ...payload(output('download')), download: '/report' }),
      'debug',
    );
    expect(loadedFrame().srcdoc).toContain('download');
    await readResponse(
      json({
        version: 1,
        id: 'debug',
        redirect: { url: '/next', replace: false },
        diagnostics: output('redirect'),
      }),
      'debug',
    );
    expect(loadedFrame().srcdoc).toContain('redirect');
  });

  it('rejects malformed diagnostics and mismatched identities before displaying them', async () => {
    await expect(
      readResponse(json(payload({ output: 'bad' })), 'debug'),
    ).rejects.toMatchObject({
      failure: { code: 'invalid_response' },
    });
    await expect(readResponse(json(payload()), 'other')).rejects.toThrow();
    await readResponse(json(payload(output('network only', false))), 'debug');
    expect(document.querySelector('aside')).toBeNull();
  });

  it('shows diagnostics on valid HTTP failures while keeping safe error feedback', async () => {
    const failed = {
      version: 1,
      error: { code: 'server_error', message: 'Safe' },
      diagnostics: output('before exception'),
    };
    await expect(
      readResponse(json(failed, 500), 'debug'),
    ).rejects.toMatchObject({
      failure: { code: 'server_error', message: 'Safe' },
    });
    expect(loadedFrame().srcdoc).toContain('before exception');
    const error = await readFailure(
      json({ ...failed, diagnostics: false }, 500),
    );
    expect(error.failure.code).toBe('invalid_response');
    await expect(
      readResponse(
        json({ ...failed, error: { code: 'unknown', message: 'Safe' } }, 500),
        'debug',
      ),
    ).rejects.toThrow();
    expect(loadedFrame().srcdoc).toContain('before exception');
  });

  it('keeps one latest request, accumulates its chunks, and supports close, open and clear', () => {
    const older = new DiagnosticRequest('older');
    const newer = new DiagnosticRequest('newer');
    older.show(output('old'));
    newer.show(output('new'));
    older.show(output('ignored'));
    newer.show(output(' chunk'));
    expect(loadedFrame().srcdoc).toContain('new chunk');
    expect(loadedFrame().srcdoc).not.toContain('ignored');
    expect(viewer().querySelector('[role="status"]')?.textContent).toContain(
      'newer',
    );
    button('Close island debug').click();
    expect(loadedFrame().hidden).toBe(true);
    button('Open island debug').click();
    expect(loadedFrame().hidden).toBe(false);
    document.querySelector('aside')?.remove();
    newer.show(output(' reattached'));
    expect(loadedFrame().srcdoc).toContain('reattached');
    button('Clear island debug').click();
    expect(document.querySelector('aside')).toBeNull();
    newer.show(output('discarded after clear'));
    expect(document.querySelector('aside')).toBeNull();
    new DiagnosticRequest('fresh').show(output('fresh'));
    expect(loadedFrame().srcdoc).toContain('fresh');
  });

  it('serializes rapid stream documents and discards queued output after navigation', () => {
    const request = new DiagnosticRequest('stream');
    request.show(output('first'));
    const target = viewer().querySelector('iframe');
    if (!target) throw new Error('Missing output frame');
    request.show(output(' second'));
    request.show(output(' third'));
    expect(target.srcdoc).toContain('<pre>first</pre>');
    target.dispatchEvent(new Event('load'));
    expect(target.srcdoc).toContain('<pre>first second third</pre>');
    request.show(output(' discarded'));
    document.dispatchEvent(new Event('interact:navigating'));
    target.dispatchEvent(new Event('load'));
    expect(target.srcdoc).toBe('');
    expect(document.querySelector('aside')).toBeNull();
    new DiagnosticRequest('fresh').show(output('fresh'));
    expect(loadedFrame().srcdoc).toContain('<pre>fresh</pre>');
    expect(loadedFrame().srcdoc).not.toContain('discarded');
  });

  it('clears retained output on navigation and ignores an older in-flight stream', () => {
    const request = new DiagnosticRequest('stream');
    request.show(output('before navigation'));
    document.dispatchEvent(new Event('interact:navigating'));
    request.show(output('after navigation'));
    expect(document.querySelector('aside')).toBeNull();
  });

  it('displays truncated output without allowing unbounded accumulation', () => {
    const request = new DiagnosticRequest('large');
    request.show(output('x'.repeat(786_432)));
    request.show(output('overflow'));
    expect(loadedFrame().srcdoc).not.toContain('overflow');
    expect(viewer().querySelector('[role="status"]')?.textContent).toContain(
      'truncated',
    );
    new DiagnosticRequest('server limit').show({
      ...output('bounded'),
      truncated: true,
    });
    expect(viewer().querySelector('[role="status"]')?.textContent).toContain(
      'truncated',
    );
  });

  it('extracts readable CakePHP dumps and never mounts captured scripts or resources', () => {
    new DiagnosticRequest('unsafe').show(
      output(
        '<div class="cake-debug"><style>bad</style><script>throw 1</script><span>&lt;img src=x&gt; &amp; value</span><img src="https://invalid.example/pixel"></div>',
      ),
    );
    expect(loadedFrame().srcdoc).toContain('&lt;img src=x&gt; &amp; value');
    expect(loadedFrame().srcdoc).not.toContain('<script');
    expect(loadedFrame().srcdoc).not.toContain('invalid.example');
    expect(loadedFrame().srcdoc).not.toContain('<style');
    expect(document.querySelector('img')).toBeNull();
    new DiagnosticRequest('plain').show(output('<script>literal</script>'));
    expect(loadedFrame().srcdoc).toContain(
      '&lt;script&gt;literal&lt;/script&gt;',
    );
  });

  it('copies readable output and reports clipboard denial without failing requests', async () => {
    const previous = Object.getOwnPropertyDescriptor(navigator, 'clipboard');
    const writeText = vi.fn().mockResolvedValue(undefined);
    Object.defineProperty(navigator, 'clipboard', {
      configurable: true,
      value: { writeText },
    });
    try {
      new DiagnosticRequest('copy').show(
        output('<div class="cake-debug">Readable &amp; safe</div>'),
      );
      button('Copy island debug').click();
      await vi.waitFor(() =>
        expect(writeText).toHaveBeenCalledWith('Readable & safe'),
      );
      expect(button('Copy island debug').textContent).toBe('Copied');
      writeText.mockRejectedValue(new Error('Clipboard denied'));
      button('Copy island debug').click();
      await vi.waitFor(() =>
        expect(button('Copy island debug').textContent).toBe('Copy failed'),
      );
    } finally {
      if (previous) Object.defineProperty(navigator, 'clipboard', previous);
      else Reflect.deleteProperty(navigator, 'clipboard');
    }
  });

  it.each(['light', 'dark'])(
    'respects an application-owned %s color scheme in the frame',
    (scheme) => {
      const previous = document.documentElement.style.colorScheme;
      document.documentElement.style.colorScheme = scheme;
      try {
        new DiagnosticRequest('theme').show(output('themed'));
        expect(loadedFrame().srcdoc).toContain(
          `name="color-scheme" content="${scheme}"`,
        );
        expect(loadedFrame().style.colorScheme).toBe(scheme);
      } finally {
        document.documentElement.style.colorScheme = previous;
      }
    },
  );

  it.each([
    ['light', 'light'],
    ['dark', 'dark'],
    ['light dark', 'light dark'],
    ['light"><script>unsafe</script>', 'dark'],
  ])(
    'bridges only supported custom color schemes (%s) into the isolated output',
    (override, expected) => {
      const previous = document.documentElement.style.colorScheme;
      document.documentElement.style.colorScheme = 'dark';
      new DiagnosticRequest('initial theme').show(output('initial'));
      const host = document.querySelector<HTMLElement>(
        'aside[aria-label="Island debug"]',
      );
      if (!host) throw new Error('Missing viewer host');
      host.style.setProperty('--interact-debug-color-scheme', override);
      try {
        new DiagnosticRequest('custom theme').show(output('custom'));
        expect(loadedFrame().srcdoc).toContain(
          `name="color-scheme" content="${expected}"`,
        );
        expect(loadedFrame().srcdoc).not.toContain('<script>');
      } finally {
        host.style.removeProperty('--interact-debug-color-scheme');
        document.documentElement.style.colorScheme = previous;
      }
    },
  );

  it('handles SSE diagnostics separately from public progress and terminal payloads', async () => {
    const receive = vi.fn();
    const messages = [
      {
        version: 1,
        id: 'debug',
        type: 'diagnostic',
        diagnostics: output('first '),
      },
      {
        version: 1,
        id: 'debug',
        type: 'target',
        to: 'progress',
        content: 'Progress',
        replace: true,
        html: false,
      },
      {
        version: 1,
        id: 'debug',
        type: 'diagnostic',
        diagnostics: output('second'),
      },
      {
        version: 1,
        id: 'debug',
        type: 'result',
        status: 200,
        payload: payload(output(' final')),
      },
    ];
    await readResponse(stream(messages), 'debug', receive);
    expect(receive).toHaveBeenCalledTimes(1);
    expect(loadedFrame().srcdoc).toContain('first second final');
    expect(loadedFrame().srcdoc).not.toContain('Progress');
    await expect(
      readResponse(
        stream([{ ...messages[0], diagnostics: false }]),
        'debug',
        receive,
      ),
    ).rejects.toThrow();
  });

  it('shows diagnostics accompanying terminal SSE failures', async () => {
    await expect(
      readResponse(
        stream([
          {
            version: 1,
            id: 'debug',
            type: 'failure',
            status: 500,
            payload: {
              version: 1,
              error: { code: 'server_error', message: 'Safe' },
              diagnostics: output('stream failed'),
            },
          },
        ]),
        'debug',
        () => {},
      ),
    ).rejects.toThrow('Safe');
    expect(loadedFrame().srcdoc).toContain('stream failed');
  });
});
