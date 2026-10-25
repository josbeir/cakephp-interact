import morph from '@alpinejs/morph';
import { RealtimeCoordinator } from './realtime/coordinator';
export type {
  RealtimeOptions,
  RealtimeTransport,
  RealtimeGrant,
  RealtimeFeedback,
  PushMessage,
  TransportCallbacks,
} from './realtime/types';
import type { Alpine } from 'alpinejs';
import { NavigationController } from './navigation/controller';
import { navigationBinding, navigationSelector } from './navigation/alpine';
import { IslandClient } from './client';
import { Relationships } from './composition/relationships';
import { registerBindings } from './bindings/alpine';
import { morphRoot } from './dom/morph';
import { timingDefaults } from './configuration';
import type { InteractOptions } from './configuration';
export type { InteractOptions } from './configuration';
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
export type { NavigationStore } from './navigation/activity';
export type { NavigationDetail } from './navigation/controller';
export type { StreamMessage } from './streaming/messages';
export type {
  RequestLifecycleDetail,
  RequestKind,
  RequestOutcome,
  RequestTimings,
} from './diagnostics/types';
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
  createInteract()(Alpine);
}

/** Create an Alpine plugin whose optional browser defaults override built-in timing. */
export function createInteract(
  options: InteractOptions = {},
): (Alpine: Alpine) => void {
  return (Alpine) => installConfigured(Alpine, options);
}

function installConfigured(Alpine: Alpine, options: InteractOptions): void {
  if (installed.has(Alpine)) return;
  const defaults = timingDefaults(options);
  const realtime = new RealtimeCoordinator(options.realtime);
  document.dispatchEvent(
    new CustomEvent('interact:configure', {
      detail: { registerTransport: realtime.registerTransport.bind(realtime) },
    }),
  );
  installed.add(Alpine);
  Alpine.plugin(morph);
  Alpine.data('interact', () => ({}));
  const clients = new WeakMap<HTMLElement, IslandClient>();
  const relationships = new Relationships(Alpine, (root) => clients.get(root));
  // Morph clones evaluate presentation bindings using the existing owner, without registering a second client.
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
  const navigation = new NavigationController(
    Alpine,
    (root, incoming) => clients.get(root)?.refreshContext(incoming),
    undefined,
    undefined,
    defaults,
  );
  Alpine.addInitSelector(() =>
    navigationSelector(Alpine.prefixed('interact:navigate')),
  );
  Alpine.addInitSelector(
    () => `[${Alpine.prefixed('interact:persist').replaceAll(':', '\\:')}]`,
  );
  registerBindings(
    Alpine,
    (element) => {
      const root = element.closest<HTMLElement>('[data-interact-id]');
      const client = root === null ? undefined : clients.get(root);
      if (!client)
        throw new Error('$server is only available inside an Interact island.');
      return client;
    },
    (element, value, modifiers, expression) =>
      navigationBinding(navigation, element, value, modifiers, expression),
    defaults,
  );
}
