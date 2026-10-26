import { clone, equal, record, state } from '../state/values';
import type { RequestResult, State } from '../types';

/** Literal browser events mapped to explicitly declared PHP action methods. */
export type ActionListeners = Record<string, string[]>;

/** Validate runtime-owned listener metadata without trusting arbitrary root attributes. */
export function actionListeners(value: unknown): value is ActionListeners {
  return (
    record(value) &&
    Object.entries(value).every(
      ([event, methods]) =>
        event === event.trim() &&
        /^[a-z][a-z0-9:-]*$/.test(event) &&
        Array.isArray(methods) &&
        methods.length > 0 &&
        methods.every(
          (method: unknown) => typeof method === 'string' && method.length > 0,
        ) &&
        new Set(methods).size === methods.length,
    )
  );
}

/**
 * Connects one mounted island's declared actions to page-wide browser events.
 *
 * Native propagation determines delivery; payloads are copied at receipt and PHP
 * remains responsible for parameter binding, type checks, and authorization.
 */
export class ActionEvents {
  private lifetime = new AbortController();
  private listeners: ActionListeners = {};
  private disposed = false;

  constructor(
    listeners: ActionListeners,
    private readonly invoke: (
      action: string,
      event: string,
      detail: State,
    ) => Promise<RequestResult>,
    private readonly failed: (cause: unknown) => void,
  ) {
    this.update(listeners);
  }

  /** Replace concrete names only after accepting new server-owned prop values. */
  update(listeners: ActionListeners): void {
    if (this.disposed || equal(this.listeners, listeners)) return;
    this.lifetime.abort();
    this.lifetime = new AbortController();
    this.listeners = listeners;
    for (const [name, methods] of Object.entries(listeners)) {
      window.addEventListener(
        name,
        (event) => {
          try {
            const detail: unknown =
              event instanceof CustomEvent ? (event.detail ?? {}) : {};
            if (!state(detail))
              throw new Error('Invalid browser event detail.');
            for (const method of methods) {
              void this.invoke(method, name, clone(detail)).catch(this.failed);
            }
          } catch (cause: unknown) {
            this.failed(cause);
          }
        },
        { signal: this.lifetime.signal },
      );
    }
  }

  /** Release every page-wide listener when the owning client leaves the document. */
  dispose(): void {
    this.disposed = true;
    this.lifetime.abort();
  }
}
