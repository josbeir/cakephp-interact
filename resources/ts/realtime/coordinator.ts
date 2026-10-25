import { mercureTransport } from './mercure';
import { RealtimeConnection } from './connection';
import type {
  RealtimeMetadata,
  RealtimeOptions,
  RealtimeOwner,
  RealtimeTransport,
} from './types';

interface ConnectionGroup {
  connection: RealtimeConnection;
  owners: Set<string>;
}

/**
 * Owns transport registration and connection sharing within one Alpine installation.
 *
 * Groups respect the authorization endpoint's 64-island batch limit. Tabs and middleware
 * endpoints retain separate authorization. No connection registry or provider credential
 * is stored globally or in persistent browser storage.
 */
export class RealtimeCoordinator {
  private readonly transports = new Map<string, RealtimeTransport>([
    ['mercure', mercureTransport],
  ]);
  private readonly connections = new Map<string, Set<ConnectionGroup>>();

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
    const groups = this.connections.get(key) ?? new Set<ConnectionGroup>();
    let group = [...groups].find((entry) => entry.owners.size < 64);
    if (!group) {
      group = {
        connection: new RealtimeConnection(endpoint, csrf, this.transports),
        owners: new Set(),
      };
      groups.add(group);
      this.connections.set(key, groups);
    }
    const selected = group;
    selected.owners.add(owner.id);
    selected.connection.update(owner, metadata);
    return {
      update: (next) => selected.connection.update(owner, next),
      dispose: () => {
        if (!selected.owners.delete(owner.id)) return;
        selected.connection.remove(owner.id);
        if (!selected.owners.size) {
          selected.connection.dispose();
          groups.delete(selected);
          if (!groups.size) this.connections.delete(key);
        }
      },
    };
  }
}
