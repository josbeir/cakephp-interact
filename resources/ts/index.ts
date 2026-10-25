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
import { installNavigation, navigationBinding } from './navigation/alpine';
import { registerIslands } from './alpine';
import { registerBindings } from './bindings/alpine';
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
export type { IslandEventMessage } from './bindings/on';
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
  const clients = registerIslands(Alpine, realtime, defaults);
  const navigation = installNavigation(
    Alpine,
    (root, incoming) => clients.get(root)?.refreshContext(incoming),
    defaults,
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
