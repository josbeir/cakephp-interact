import { mercureTransport } from './mercure';
import { RealtimeConnection } from './connection';
import type {
  RealtimeMetadata,
  RealtimeOptions,
  RealtimeOwner,
  RealtimeTransport,
} from './types';

/**
 * Owns transport registration and connection sharing within one Alpine installation.
 *
 * Tabs and middleware endpoints retain separate authorization. No connection registry
 * or provider credential is stored globally or in persistent browser storage.
 */
export class RealtimeCoordinator {
  private readonly transports = new Map<string, RealtimeTransport>([
    ['mercure', mercureTransport],
  ]);
  private readonly connections = new Map<
    string,
    { connection: RealtimeConnection; owners: Set<string> }
  >();

  constructor(options: RealtimeOptions = {}) {
    for (const [name, transport] of Object.entries(options.transports ?? {}))
      this.registerTransport(name, transport);
  }

  /** Register a provider before its first island joins a shared connection. */
  registerTransport(name: string, transport: RealtimeTransport): void {
    if (!/^[a-z][a-z0-9:-]*$/.test(name) || typeof transport !== 'function')
      throw new Error('Invalid realtime transport registration.');
    this.transports.set(name, transport);
  }

  /** Join an endpoint/CSRF scope and return reference-counted member operations. */
  join(
    owner: RealtimeOwner,
    metadata: RealtimeMetadata,
    csrf: string,
  ): { update(metadata: RealtimeMetadata): void; dispose(): void } {
    const endpoint = new URL(metadata.endpoint, window.location.href).href;
    const key = JSON.stringify([endpoint, csrf]);
    let group = this.connections.get(key);
    if (!group) {
      group = {
        connection: new RealtimeConnection(endpoint, csrf, this.transports),
        owners: new Set(),
      };
      this.connections.set(key, group);
    }
    const selected = group;
    selected.owners.add(owner.id);
    selected.connection.update(owner, metadata);
    return {
      update: (next) => selected.connection.update(owner, next),
      dispose: () => {
        selected.connection.remove(owner.id);
        selected.owners.delete(owner.id);
        if (!selected.owners.size) {
          selected.connection.dispose();
          this.connections.delete(key);
        }
      },
    };
  }
}
