import type { Json } from '../types';

/** Delivery policy exported after application authorization. */
export interface Subscription {
  topic: string;
  mode: 'refresh' | 'event' | 'stream';
  event: string | null;
  private: boolean;
}

/** Subscription-only proof and the server-owned authorization endpoint. */
export interface RealtimeMetadata {
  lease: string;
  endpoint: string;
  subscriptions: Subscription[];
}

/** Reactive feedback without exposing provider credentials. */
export interface RealtimeFeedback {
  status: 'disabled' | 'connecting' | 'connected' | 'reconnecting' | 'error';
  stale: boolean;
  error: string | null;
}

/** Provider-neutral message routed by exact topic, independently of island IDs. */
export interface PushMessage {
  version: 1;
  id: string;
  topic: string;
  event: string;
  detail: Record<string, Json>;
  display?: {
    type: 'target';
    to: string;
    content: string;
    replace: boolean;
    html: boolean;
  };
}

/** Expiring connection data interpreted only by its registered transport. */
export interface RealtimeGrant {
  driver: string;
  data: Record<string, unknown>;
  expires: number;
}

/** Transport events are scoped to one connection generation by the coordinator. */
export interface TransportCallbacks {
  receive(message: unknown): void;
  status(status: RealtimeFeedback['status'], error?: string): void;
}

/** A provider owns connection/reconnection mechanics and returns deterministic cleanup. */
export type RealtimeTransport = (
  grant: RealtimeGrant,
  callbacks: TransportCallbacks,
) => () => void;

/** Optional application-owned transports supplement the bundled Mercure adapter. */
export interface RealtimeOptions {
  transports?: Record<string, RealtimeTransport>;
}

/** The coordinator routes validated messages without coupling to DOM or Alpine. */
export interface RealtimeOwner {
  readonly id: string;
  readonly feedback: RealtimeFeedback;
  receive(message: PushMessage, subscriptions: Subscription[]): void;
  invalidate(): void;
}
