import { canonical, matches, target } from './targets';
import type { Invocation, Json, LoadingTarget, RequestTarget } from '../types';

/**
 * Exposes reactive queue activity and delayed feedback for one island.
 *
 * Equivalent queries share a timer while matching work remains pending. Activity
 * is browser-local and never becomes transported state or an execution guarantee.
 */
export class RequestActivity {
  private readonly state: { requests: readonly Invocation[] };
  private readonly accepted = new Map<Invocation, number>();
  private readonly delayed = new Map<
    string,
    {
      query: RequestTarget;
      visible: { value: boolean };
      timer: ReturnType<typeof setTimeout> | undefined;
    }
  >();

  /**
   * Use the host Alpine instance's reactivity for queue and timer transitions.
   */
  constructor(private readonly reactive: <T extends object>(value: T) => T) {
    this.state = reactive({ requests: [] });
  }

  /**
   * Publish accepted queue work and end delayed intervals whose targets settled.
   */
  update(requests: readonly Invocation[]): void {
    for (const request of this.accepted.keys()) {
      if (!requests.includes(request)) this.accepted.delete(request);
    }
    for (const request of requests) {
      if (!this.accepted.has(request)) this.accepted.set(request, Date.now());
    }
    this.state.requests = requests;
    for (const [key, entry] of this.delayed) {
      if (this.state.requests.some((request) => matches(entry.query, request)))
        continue;
      clearTimeout(entry.timer);
      entry.visible.value = false;
      this.delayed.delete(key);
    }
  }

  /**
   * Report matching queued or executing work immediately.
   */
  pending(selector?: string | null | RequestTarget, ...args: Json[]): boolean {
    const query = target(selector, args);
    return this.state.requests.some((request) => matches(query, request));
  }

  /**
   * Report a continuously pending target after its configurable visual delay.
   */
  loading(selector?: string | null | LoadingTarget, ...args: Json[]): boolean {
    const query = target(selector, args);
    if (!this.pending(query)) return false;
    const delay = query.delay ?? 200;
    if (delay === 0) return true;
    const key = canonical({ ...query, delay });
    let entry = this.delayed.get(key);
    if (!entry) {
      const started = Math.min(
        ...[...this.accepted]
          .filter(([request]) => matches(query, request))
          .map(([, time]) => time),
      );
      const remaining = Math.max(0, started + delay - Date.now());
      const visible = this.reactive({ value: remaining === 0 });
      entry = {
        query,
        visible,
        timer:
          remaining === 0
            ? undefined
            : setTimeout(() => {
                visible.value = true;
              }, remaining),
      };
      this.delayed.set(key, entry);
    }
    return entry.visible.value;
  }

  /**
   * Cancel all visual timers and release pending state when the island disappears.
   */
  dispose(): void {
    this.update([]);
  }
}
