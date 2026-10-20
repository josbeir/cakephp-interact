import type { Uploader } from '../../resources/ts/uploads/types';
import Alpine from '@alpinejs/csp';
import { IslandClient } from '../../resources/ts/client';
import { registerBindings } from '../../resources/ts/bindings/alpine';
import type { Fetcher, Patcher } from '../../resources/ts/types';

const clients = new WeakMap<HTMLElement, IslandClient>();
registerBindings(Alpine, (element) => {
  const root = element.closest<HTMLElement>('[data-interact-id]');
  const client = root ? clients.get(root) : undefined;
  if (!client) throw new Error('Missing test island.');
  return client;
});

/**
 * Exercise the coordinator with real CSP directives and a controlled HTTP boundary.
 *
 * Replacement patches explicitly run Alpine's lifecycle, matching the production
 * morph integration while keeping transport and state tests deterministic.
 */
export function mountClient(
  root: HTMLElement,
  fetcher: Fetcher,
  patch: Patcher = (from, to) => {
    from.innerHTML = to.innerHTML;
  },
  uploader?: Uploader,
): IslandClient {
  root.setAttribute('x-data', '{}');
  const client = new IslandClient(
    root,
    (from, to) => {
      Alpine.mutateDom(() => {
        Alpine.destroyTree(from);
        try {
          patch(from, to);
        } finally {
          Alpine.initTree(from);
        }
      });
    },
    Alpine.reactive,
    fetcher,
    uploader,
  );
  clients.set(root, client);
  Alpine.initTree(root);
  return client;
}

/**
 * Dispose directive registrations before clearing a fixture's DOM.
 */
export function destroyBindings(root: HTMLElement): void {
  Alpine.destroyTree(root);
}

/**
 * Reinitialize a fixture after deliberately changing its directive declarations.
 */
export function refreshBindings(root: HTMLElement): void {
  Alpine.destroyTree(root);
  Alpine.initTree(root);
}
