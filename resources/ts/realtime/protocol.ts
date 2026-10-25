import { record, state } from '../state/values';
import { identifier, streamMessage } from '../streaming/messages';
import type {
  PushMessage,
  RealtimeGrant,
  RealtimeMetadata,
  Subscription,
} from './types';

/** Encode the subscription endpoint's versioned lease record. */
export function authorizationBody(leases: ReadonlyMap<string, string>): string {
  return JSON.stringify({ version: 1, leases: Object.fromEntries(leases) });
}

/** Match both server request limits, including JSON escaping and UTF-8 bytes. */
export function authorizationFits(
  leases: ReadonlyMap<string, string>,
): boolean {
  return (
    leases.size <= 64 &&
    new TextEncoder().encode(authorizationBody(leases)).length <= 1_048_576
  );
}

/** Exact topic validation mirrors the publishing boundary, including wildcard rejection. */
export function topic(value: unknown): value is string {
  return (
    typeof value === 'string' &&
    value.length > 0 &&
    value !== '*' &&
    new TextEncoder().encode(value).length <= 2048 &&
    !Array.from(value).some(
      (character) =>
        character.charCodeAt(0) <= 32 || character.charCodeAt(0) === 127,
    )
  );
}

/** Validate server-generated delivery declarations before allocating connections. */
export function subscriptions(value: unknown): value is Subscription[] {
  return (
    Array.isArray(value) &&
    value.length <= 128 &&
    value.every(
      (entry: unknown) =>
        record(entry) &&
        topic(entry.topic) &&
        ['refresh', 'event', 'stream'].includes(String(entry.mode)) &&
        (entry.event === null || identifier(entry.event)) &&
        typeof entry.private === 'boolean',
    )
  );
}

/** Require a same-origin grant endpoint; provider URLs are validated by the transport. */
export function realtimeMetadata(value: unknown): value is RealtimeMetadata {
  if (
    !record(value) ||
    typeof value.lease !== 'string' ||
    !value.lease ||
    typeof value.endpoint !== 'string' ||
    !subscriptions(value.subscriptions)
  )
    return false;
  try {
    return (
      new URL(value.endpoint, window.location.href).origin ===
      window.location.origin
    );
  } catch {
    return false;
  }
}

/** Validate the common grant without interpreting provider-specific data. */
export function realtimeGrant(value: unknown): value is RealtimeGrant {
  return (
    record(value) &&
    identifier(value.driver) &&
    record(value.data) &&
    typeof value.expires === 'number' &&
    Number.isSafeInteger(value.expires) &&
    value.expires > Date.now() / 1000
  );
}

/** Push is restricted to notifications and the existing provisional display vocabulary. */
export function pushMessage(value: unknown): value is PushMessage {
  return (
    record(value) &&
    value.version === 1 &&
    typeof value.id === 'string' &&
    /^[a-zA-Z0-9:_-]{1,128}$/.test(value.id) &&
    topic(value.topic) &&
    identifier(value.event) &&
    state(value.detail) &&
    (value.display === undefined ||
      (record(value.display) &&
        streamMessage({
          ...value.display,
          version: 1,
          id: 'receiving-island',
        }) &&
        value.display.type === 'target'))
  );
}
