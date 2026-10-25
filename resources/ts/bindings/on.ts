import { ownsIslandElement } from '../dom/ownership';
import { record, state } from '../state/values';
import { identifier } from '../streaming/messages';
import type { State } from '../types';

/** Original DOM envelope exposed as optional $message listener context. */
export type IslandEventMessage =
  | { id: string; topic: string; event: string; detail: State }
  | { name: string; detail: State };

/** Recognize the existing push and stream envelopes without trusting arbitrary DOM events. */
function eventMessage(
  value: unknown,
  type: string,
): value is IslandEventMessage {
  return (
    record(value) &&
    state(value.detail) &&
    (type === 'interact:push'
      ? typeof value.id === 'string' &&
        typeof value.topic === 'string' &&
        identifier(value.event)
      : identifier(value.name))
  );
}

/**
 * Bind x-interact:on expressions to named push and stream events in their owning island.
 *
 * The expression receives a local event with flat detail and the original envelope.
 * Neither is redispatched, so existing raw observers retain their delivery semantics.
 */
export function bindIslandEvent(
  root: HTMLElement,
  element: HTMLElement,
  name: string,
  receive: (event: CustomEvent<State>, message: IslandEventMessage) => unknown,
): () => void {
  const listener = (event: Event) => {
    if (
      event.target !== root ||
      !ownsIslandElement(root, element) ||
      !(event instanceof CustomEvent)
    )
      return;
    const message: unknown = event.detail;
    if (!eventMessage(message, event.type)) return;
    const incoming = 'event' in message ? message.event : message.name;
    if (incoming !== name) return;
    void Promise.resolve(
      receive(new CustomEvent(name, { detail: message.detail }), message),
    ).catch((cause: unknown) => window.reportError(cause));
  };
  root.addEventListener('interact:push', listener);
  root.addEventListener('interact:stream', listener);
  return () => {
    root.removeEventListener('interact:push', listener);
    root.removeEventListener('interact:stream', listener);
  };
}
