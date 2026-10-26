import type { Alpine } from 'alpinejs';
import { IslandClient } from './client';
import { Relationships } from './composition/relationships';
import type { TimingDefaults } from './configuration';
import { morphRoot } from './dom/morph';
import type { RealtimeCoordinator } from './realtime/coordinator';

/**
 * Discover island clients and release their effects and resources with Alpine's DOM lifecycle.
 *
 * The returned map belongs to this installation. Morph clones share their original
 * client for expression evaluation without acquiring another lifecycle owner.
 */
export function registerIslands(
  Alpine: Alpine,
  realtime: RealtimeCoordinator,
  defaults: TimingDefaults,
): WeakMap<HTMLElement, IslandClient> {
  Alpine.data('interact', () => ({}));
  const clients = new WeakMap<HTMLElement, IslandClient>();
  const relationships = new Relationships(Alpine, (root) => clients.get(root));
  Alpine.interceptClone((from, to) => {
    const client = from instanceof HTMLElement ? clients.get(from) : undefined;
    if (client && to instanceof HTMLElement) clients.set(to, client);
  });
  Alpine.interceptInit(
    Alpine.skipDuringClone((element) => {
      if (
        !(element instanceof HTMLElement) ||
        !element.hasAttribute('data-interact-id') ||
        clients.has(element)
      )
        return;
      const client = new IslandClient(
        element,
        morphRoot(Alpine),
        Alpine.reactive,
        undefined,
        undefined,
        undefined,
        defaults,
      );
      clients.set(element, client);
      relationships.register(client);
      client.attachRealtime(realtime);
      const effect = Alpine.effect(() => client.flushRealtime());
      Alpine.onElRemoved(element, () => {
        Alpine.release(effect);
        client.dispose();
      });
    }),
  );
  return clients;
}
