import Alpine from '@alpinejs/csp';
import { afterEach, expect, it, vi } from 'vite-plus/test';
import install from '../../../resources/ts/index';
import type { Fetcher } from '../../../resources/ts/types';

const marker = `<meta name="interact-navigation" content='{"version":"1","application":"test","session":"same","policy":"same"}'>`;

function composition(parent: string, child: string, csrf: string): string {
  return `<aside x-interact:persist="editor"><div x-data="interact" data-interact-id="${parent}" data-interact-mount="parent" data-interact-state='{"title":"original"}' data-interact-writable='{"title":{"type":"string","nullable":false}}' data-interact-snapshot="parent-signed" data-interact-endpoint="/_interact/update" data-interact-csrf="${csrf}"><input aria-label="Parent draft" x-interact:model="title"><div x-data="interact" data-interact-id="${child}" data-interact-mount="child" data-interact-state='{"value":"original"}' data-interact-writable='{"value":{"type":"string","nullable":false}}' data-interact-snapshot="child-signed" data-interact-endpoint="/_interact/update" data-interact-csrf="${csrf}" data-interact-relation='{"parentId":"${parent}","models":{"value":{"path":"title","timing":"defer","delay":250}}}'><input aria-label="Child draft" x-interact:model="value"></div></div></aside>`;
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

it('retains both clients and their bidirectional drafts through compatible persistent navigation', async () => {
  window.history.replaceState(null, '', '/first');
  document.head.innerHTML = marker;
  document.body.innerHTML = `${composition('parent', 'child', 'old')}<main><a href="/second" x-interact:navigate>Second</a></main>`;
  const fetcher = vi.fn<Fetcher>(
    async () =>
      new Response(
        `<!doctype html><html><head>${marker}<title>Second page</title></head><body>${composition('incoming-parent', 'incoming-child', 'fresh')}<main><h1>Second</h1></main></body></html>`,
        { headers: { 'Content-Type': 'text/html' } },
      ),
  );
  vi.stubGlobal('fetch', fetcher);
  vi.spyOn(window, 'scrollTo').mockImplementation(() => {});
  install(Alpine);
  Alpine.initTree(document.body);
  const parent = document.querySelector<HTMLInputElement>(
    '[aria-label="Parent draft"]',
  );
  const child = document.querySelector<HTMLInputElement>(
    '[aria-label="Child draft"]',
  );
  const link = document.querySelector('a');
  if (!parent || !child || !link) throw new Error('Missing composition.');
  await Promise.resolve();
  child.value = 'Unsent child draft';
  child.dispatchEvent(new Event('input', { bubbles: true }));
  await vi.waitFor(() => expect(parent.value).toBe('Unsent child draft'));
  link.click();
  await vi.waitFor(() => expect(document.title).toBe('Second page'));
  expect(document.querySelector('[aria-label="Child draft"]')).toBe(child);
  expect(child.value).toBe('Unsent child draft');
  expect(
    child.closest<HTMLElement>('[data-interact-id]')?.dataset.interactCsrf,
  ).toBe('fresh');
  parent.value = 'Edited after navigation';
  parent.dispatchEvent(new Event('input', { bubbles: true }));
  await vi.waitFor(() => expect(child.value).toBe('Edited after navigation'));
  expect(fetcher).toHaveBeenCalledTimes(1);
});
