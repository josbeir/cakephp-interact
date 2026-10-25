import { mercureTransport } from './mercure';
import { RealtimeConnection } from './connection';
import { authorizationFits } from './protocol';
import type {
  RealtimeMetadata,
  RealtimeOptions,
  RealtimeOwner,
  RealtimeTransport,
} from './types';

interface ConnectionGroup {
  connection: RealtimeConnection;
  members: Map<string, Membership>;
}

interface ConnectionScope {
  endpoint: string;
  csrf: string;
  groups: Set<ConnectionGroup>;
}

interface Membership {
  owner: RealtimeOwner;
  metadata: RealtimeMetadata;
  group: ConnectionGroup;
}

/**
 * Owns transport registration and connection sharing within one Alpine installation.
 *
 * Groups respect the authorization endpoint's count and byte limits, including renewed
 * proofs. Tabs and middleware endpoints retain separate authorization. No connection
 * registry or provider credential is stored globally or in persistent browser storage.
 */
export class RealtimeCoordinator {
  private readonly transports = new Map<string, RealtimeTransport>([
    ['mercure', mercureTransport],
  ]);
  private readonly scopes = new Map<string, ConnectionScope>();

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
  ): {
    current(): RealtimeMetadata;
    update(metadata: RealtimeMetadata): void;
    dispose(): void;
  } {
    const endpoint = new URL(metadata.endpoint, window.location.href).href;
    const key = JSON.stringify([endpoint, csrf]);
    const scope = this.scopes.get(key) ?? { endpoint, csrf, groups: new Set() };
    this.scopes.set(key, scope);
    const group = this.select(scope, owner.id, metadata);
    const member = { owner, metadata, group };
    group.members.set(owner.id, member);
    group.connection.update(owner, metadata);
    let disposed = false;
    return {
      current: () => member.metadata,
      update: (next) => {
        if (!disposed) this.place(scope, member, next);
      },
      dispose: () => {
        if (disposed) return;
        disposed = true;
        this.release(scope, member);
        if (!scope.groups.size) this.scopes.delete(key);
      },
    };
  }

  /** Select capacity without interrupting grants belonging to other groups. */
  private select(
    scope: ConnectionScope,
    id: string,
    metadata: RealtimeMetadata,
  ): ConnectionGroup {
    const available = [...scope.groups].find((group) =>
      this.fits(group, id, metadata),
    );
    if (available) return available;
    const group: ConnectionGroup = {
      members: new Map(),
      connection: new RealtimeConnection(
        scope.endpoint,
        scope.csrf,
        this.transports,
        (renewed) => {
          for (const [id, metadata] of renewed) {
            const member = group.members.get(id);
            if (member) member.metadata = metadata;
          }
          for (const member of [...group.members.values()]) {
            if (
              group.members.size < 2 ||
              this.fits(group, member.owner.id, member.metadata)
            )
              break;
            this.place(scope, member, member.metadata);
          }
        },
      ),
    };
    scope.groups.add(group);
    return group;
  }

  /** Measure the exact outgoing record with the candidate proof replacing its old value. */
  private fits(
    group: ConnectionGroup,
    id: string,
    metadata: RealtimeMetadata,
  ): boolean {
    const leases = new Map(
      [...group.members].map(([id, member]) => [id, member.metadata.lease]),
    );
    leases.set(id, metadata.lease);
    return authorizationFits(leases);
  }

  /** Move only a growing member when its current authorization batch no longer fits. */
  private place(
    scope: ConnectionScope,
    member: Membership,
    metadata: RealtimeMetadata,
  ): void {
    if (!this.fits(member.group, member.owner.id, metadata)) {
      this.release(scope, member);
      member.group = this.select(scope, member.owner.id, metadata);
      member.group.members.set(member.owner.id, member);
    }
    member.metadata = metadata;
    member.group.connection.update(member.owner, metadata);
  }

  /** Release group resources without removing the scope while members migrate. */
  private release(scope: ConnectionScope, member: Membership): void {
    const group = member.group;
    group.members.delete(member.owner.id);
    group.connection.remove(member.owner.id);
    if (!group.members.size) {
      group.connection.dispose();
      scope.groups.delete(group);
    }
  }
}
