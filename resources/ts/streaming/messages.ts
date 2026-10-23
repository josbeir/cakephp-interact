import type { State } from '../types';
import { record, state } from '../state/values';

/** Incremental display data accepted by the public receiver, independent of its transport. */
export type StreamMessage = { version: 1; id: string } & (
  | {
      type: 'target';
      to: string;
      content: string;
      replace: boolean;
      html: boolean;
    }
  | { type: 'event'; name: string; detail: State }
);

/** Require the same bounded JSON vocabulary and identifiers as PHP streaming actions. */
export function streamMessage(value: unknown): value is StreamMessage {
  if (!record(value) || value.version !== 1 || typeof value.id !== 'string')
    return false;
  if (value.type === 'event')
    return identifier(value.name) && state(value.detail);
  return (
    value.type === 'target' &&
    identifier(value.to) &&
    typeof value.content === 'string' &&
    typeof value.replace === 'boolean' &&
    typeof value.html === 'boolean'
  );
}

/** Recognize literal names, never Alpine expressions or selectors. */
export function identifier(value: unknown): value is string {
  return typeof value === 'string' && /^[a-z][a-z0-9:-]*$/.test(value);
}
