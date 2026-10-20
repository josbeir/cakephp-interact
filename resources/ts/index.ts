import morph from '@alpinejs/morph';
import type { Alpine } from 'alpinejs';
import { IslandClient } from './client';
import { registerBindings } from './bindings/alpine';
import { morphRoot } from './dom/morph';
export type {
  IslandBridge,
  RequestFailure,
  FailureCode,
  RequestResult,
  RequestTarget,
  LoadingTarget,
} from './types';
export type {
  UploadField,
  UploadItem,
  UploadedFileInfo,
  Uploader,
} from './uploads/types';
export { IslandClient } from './client';
const installed = new WeakSet<Alpine>();

/**
 * Install island discovery, keyed morphing, and the reactive `$server` bridge.
 *
 * Register this plugin before starting the application's Alpine instance. Repeated
 * installation on the same instance is ignored; the host remains responsible for startup.
 *
 * @example
 * ```ts
 * Alpine.plugin(interact);
 * Alpine.start();
 * ```
 */
export default function install(Alpine: Alpine): void {
  if (installed.has(Alpine)) return;
  installed.add(Alpine);
  Alpine.plugin(morph);
  Alpine.data('interact', () => ({}));
  const clients = new WeakMap<HTMLElement, IslandClient>();
  Alpine.interceptInit((element) => {
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
    );
    clients.set(element, client);
    Alpine.onElRemoved(element, () => client.dispose());
  });
  registerBindings(Alpine, (element) => {
    const root = element.closest<HTMLElement>('[data-interact-id]');
    const client = root === null ? undefined : clients.get(root);
    if (!client)
      throw new Error('$server is only available inside an Interact island.');
    return client;
  });
}
