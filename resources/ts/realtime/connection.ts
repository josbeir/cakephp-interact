import { record } from '../state/values';
import { pushMessage, realtimeGrant, subscriptions } from './protocol';
import type {
  RealtimeMetadata,
  RealtimeOwner,
  RealtimeTransport,
} from './types';

interface Member {
  owner: RealtimeOwner;
  metadata: RealtimeMetadata;
}

/**
 * Shares one provider connection across authorized islands in one endpoint/CSRF scope.
 *
 * Grant generations reject obsolete renewals, while each owner keeps independent delivery
 * policy. Removing or rejecting one owner does not disconnect unrelated subscriptions.
 */
export class RealtimeConnection {
  private readonly members = new Map<string, Member>();
  private generation = 0;
  private active = 0;
  private scheduled = false;
  private requesting = false;
  private close: (() => void) | undefined;
  private renewal: ReturnType<typeof setTimeout> | undefined;
  private expiry: ReturnType<typeof setTimeout> | undefined;
  private readonly seen = new Set<string>();
  private readonly lifetime = new AbortController();

  constructor(
    private readonly endpoint: string,
    private readonly csrf: string,
    private readonly transports: ReadonlyMap<string, RealtimeTransport>,
  ) {}

  /** Register a member, reconnecting only when the effective declarations change. */
  update(owner: RealtimeOwner, metadata: RealtimeMetadata): void {
    const previous = this.members.get(owner.id);
    this.members.set(owner.id, { owner, metadata });
    if (
      !previous ||
      JSON.stringify(previous.metadata.subscriptions) !==
        JSON.stringify(metadata.subscriptions) ||
      owner.feedback.status === 'error'
    )
      this.schedule();
  }

  /** Release a member and rebuild the shared subscription set if it still has owners. */
  remove(id: string): void {
    if (!this.members.delete(id)) return;
    this.schedule();
  }

  /** Abort grant requests, connection readers, and both credential timers. */
  dispose(): void {
    this.lifetime.abort();
    this.generation++;
    this.disconnect();
    clearTimeout(this.renewal);
    clearTimeout(this.expiry);
    this.members.clear();
  }

  /** Coalesce same-turn island registration into one authorization request. */
  private schedule(): void {
    this.generation++;
    clearTimeout(this.renewal);
    if (this.scheduled || this.lifetime.signal.aborted) return;
    this.scheduled = true;
    queueMicrotask(() => {
      this.scheduled = false;
      void this.authorize();
    });
  }

  private async authorize(): Promise<void> {
    if (this.requesting || this.lifetime.signal.aborted) return;
    if (!this.members.size) {
      this.disconnect();
      clearTimeout(this.expiry);
      return;
    }
    const generation = this.generation;
    this.requesting = true;
    const leases: Record<string, string> = {};
    for (const [id, member] of this.members) {
      leases[id] = member.metadata.lease;
      if (!this.close) member.owner.feedback.status = 'connecting';
    }
    try {
      const response = await fetch(this.endpoint, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          Interact: 'true',
          'X-CSRF-Token': this.csrf,
        },
        credentials: 'same-origin',
        redirect: 'error',
        signal: this.lifetime.signal,
        body: JSON.stringify({ version: 1, leases }),
      });
      if (!response.ok) throw new Error('Realtime authorization failed.');
      const value: unknown = await response.json();
      if (this.lifetime.signal.aborted || generation !== this.generation)
        return;
      if (!record(value) || value.version !== 1 || !record(value.islands))
        throw new Error('Invalid realtime authorization response.');
      const results = value.islands;
      for (const [id, member] of this.members) {
        const result = results[id];
        if (
          !record(result) ||
          typeof result.lease !== 'string' ||
          !subscriptions(result.subscriptions)
        ) {
          member.owner.feedback.status = 'error';
          member.owner.feedback.error = 'Subscription authorization failed.';
          member.metadata = { ...member.metadata, subscriptions: [] };
          continue;
        }
        member.metadata = {
          ...member.metadata,
          lease: result.lease,
          subscriptions: result.subscriptions,
        };
        if (!result.subscriptions.length) {
          member.owner.feedback.status = 'disabled';
          member.owner.feedback.error = null;
          member.owner.feedback.stale = false;
        }
      }
      this.disconnect();
      clearTimeout(this.expiry);
      if (value.grant === null) return;
      if (!realtimeGrant(value.grant))
        throw new Error('Invalid realtime grant.');
      const transport = this.transports.get(value.grant.driver);
      if (!transport) throw new Error('Realtime transport is not registered.');
      const active = ++this.active;
      this.close = transport(value.grant, {
        receive: (message) => {
          if (active !== this.active || this.lifetime.signal.aborted) return;
          if (!pushMessage(message)) {
            this.fail('Invalid realtime message.');
            return;
          }
          const key = `${message.topic}\0${message.id}`;
          if (this.seen.has(key)) return;
          this.seen.add(key);
          if (this.seen.size > 512) {
            const oldest = this.seen.values().next().value;
            if (oldest !== undefined) this.seen.delete(oldest);
          }
          for (const member of this.members.values()) {
            const matches = member.metadata.subscriptions.filter(
              (entry) =>
                entry.topic === message.topic &&
                (entry.event === null || entry.event === message.event),
            );
            if (matches.length) {
              try {
                member.owner.receive(message, matches);
              } catch {
                member.owner.feedback.error = 'Realtime display update failed.';
              }
            }
          }
        },
        status: (status, error) => {
          if (active !== this.active || this.lifetime.signal.aborted) return;
          for (const member of this.members.values()) {
            if (!member.metadata.subscriptions.length) continue;
            member.owner.feedback.status = status;
            member.owner.feedback.error = error ?? null;
            if (
              status === 'connected' &&
              member.metadata.subscriptions.some(
                (entry) => entry.mode === 'refresh',
              )
            )
              member.owner.invalidate();
          }
        },
      });
      const lifetime = value.grant.expires * 1000 - Date.now();
      clearTimeout(this.expiry);
      this.expiry = setTimeout(
        () => this.fail('Realtime authorization expired.'),
        lifetime,
      );
      this.renewal = setTimeout(
        () => this.schedule(),
        Math.max(100, lifetime - 60_000),
      );
    } catch {
      if (!this.lifetime.signal.aborted && generation === this.generation) {
        if (!this.close) this.fail('Realtime authorization failed.');
        this.renewal = setTimeout(() => this.schedule(), 5000);
      }
    } finally {
      this.requesting = false;
      if (!this.lifetime.signal.aborted && generation !== this.generation)
        this.schedule();
    }
  }

  private disconnect(): void {
    this.active++;
    this.close?.();
    this.close = undefined;
  }

  private fail(error: string): void {
    this.disconnect();
    for (const member of this.members.values()) {
      if (!member.metadata.subscriptions.length) continue;
      member.owner.feedback.status = 'error';
      member.owner.feedback.error = error;
    }
  }
}
