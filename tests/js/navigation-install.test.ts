import { afterEach, expect, it, vi } from 'vite-plus/test';
import Alpine from '@alpinejs/csp';
import install from '../../resources/ts/index';
import type { Fetcher } from '../../resources/ts/types';

const marker = `<meta name="interact-navigation" content='{"version":"1","application":"test","session":"same","policy":"same"}'>`;
function island(id: string, csrf: string): string {
  return `<div x-data="interact" data-interact-id="${id}" data-interact-mount="same" data-interact-state='{"title":"original"}' data-interact-writable='{"title":{"type":"string","nullable":false}}' data-interact-snapshot="signed" data-interact-endpoint="/_interact/update" data-interact-csrf="${csrf}"><input aria-label="Draft" x-interact:model="title"><button @click="$server.call('save')">Save</button></div>`;
}
afterEach(() => {
  Alpine.destroyTree(document.body);
  window.dispatchEvent(new PageTransitionEvent('pagehide'));
  document.body.replaceChildren();
  document.head.replaceChildren();
  window.history.replaceState(null, '', '/');
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

it('routes page directives outside islands through the installer and refreshes a retained client before its next action', async () => {
  window.history.replaceState({ application: true }, '', '/first');
  document.head.innerHTML = marker;
  document.body.innerHTML = `<aside x-interact:persist="notebook">${island('retained', 'old')}</aside><main><a href="/second" x-interact:navigate.preserve-scroll>Second</a></main>`;
  const fetcher = vi.fn<Fetcher>(async (_url, options) => {
    if (options.method === 'POST')
      return new Response(
        JSON.stringify({
          version: 1,
          id: 'retained',
          html: island('retained', 'fresh'),
          snapshot: 'next',
          state: { title: 'unsent draft' },
          writable: { title: { type: 'string', nullable: false } },
          errors: {},
          events: [],
        }),
        { headers: { 'Content-Type': 'application/json' } },
      );
    return new Response(
      `<!doctype html><html><head>${marker}<title>Second page</title></head><body><aside x-interact:persist="notebook">${island('incoming', 'fresh')}</aside><main><h1>Second</h1></main></body></html>`,
      { headers: { 'Content-Type': 'text/html' } },
    );
  });
  vi.stubGlobal('fetch', fetcher);
  vi.spyOn(window, 'scrollTo').mockImplementation(() => {});
  install(Alpine);
  Alpine.initTree(document.body);
  const input = document.querySelector('input');
  const root = document.querySelector<HTMLElement>('[data-interact-id]');
  const link = document.querySelector('a');
  if (!input || !root || !link) throw new Error('Missing navigation fixture.');
  input.value = 'unsent draft';
  input.dispatchEvent(new Event('input', { bubbles: true }));
  link.click();
  await vi.waitFor(() => expect(document.title).toBe('Second page'));
  expect(document.querySelector('[data-interact-id]')).toBe(root);
  expect(document.querySelector('input')).toBe(input);
  expect(input.value).toBe('unsent draft');
  expect(root.dataset.interactCsrf).toBe('fresh');
  root.querySelector('button')?.click();
  await vi.waitFor(() => expect(fetcher).toHaveBeenCalledTimes(2));
  expect(fetcher.mock.calls[1]?.[1].headers).toHaveProperty(
    'X-CSRF-Token',
    'fresh',
  );
  await vi.waitFor(() => expect(root.hasAttribute('aria-busy')).toBe(false));
  expect(document.querySelector('[data-interact-id]')).toBe(root);
});
