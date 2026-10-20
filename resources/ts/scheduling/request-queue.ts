import type { Invocation } from '../types';

/**
 * Retains completion handlers for an action or a coalesced synchronization request.
 */
type Pending = Invocation & {
  resolve: (() => void)[];
  reject: ((error: Error) => void)[];
};

/**
 * Serializes requests for one island while retaining action order.
 *
 * Pending synchronization requests coalesce; actions remain separate. Failures
 * reject pending work without retrying operations whose server outcome is unknown.
 */
export class RequestQueue {
  private readonly pending: Pending[] = [];
  private active: Pending | undefined;
  private disposed = false;

  /**
   * Bind execution and error reporting without coupling scheduling to fetch or DOM APIs.
   */
  constructor(
    private readonly execute: (invocation: Invocation) => Promise<void>,
    private readonly onFailure: (error: Error) => void,
    private readonly onChange: (requests: readonly Invocation[]) => void,
  ) {}

  /**
   * Queue an invocation and settle its promise after execution and response effects.
   */
  enqueue(invocation: Invocation): Promise<void> {
    if (this.disposed) return Promise.reject(new Error('Island was removed.'));
    return new Promise((resolve, reject) => {
      const existing =
        invocation.action === null
          ? this.pending.find((task) => task.action === null)
          : undefined;
      if (existing) {
        existing.resolve.push(resolve);
        existing.reject.push(reject);
      } else {
        this.pending.push({
          ...invocation,
          resolve: [resolve],
          reject: [reject],
        });
      }
      this.publish();
      void this.drain();
    });
  }

  /**
   * Reject unsent work; the owner remains responsible for aborting browser transport.
   */
  dispose(): void {
    this.disposed = true;
    this.rejectPending(new Error('Island was removed.'));
    this.publish();
  }

  /**
   * Execute at most one invocation and propagate failures to every pending caller.
   */
  private async drain(): Promise<void> {
    if (this.active || this.disposed) return;
    const task = this.pending.shift();
    if (!task) return;
    this.active = task;
    let failure: Error | null = null;
    try {
      await this.execute({ action: task.action, args: task.args });
      task.resolve.forEach((resolve) => resolve());
    } catch (error: unknown) {
      const rejected =
        error instanceof Error ? error : new Error('Island request failed.');
      failure = rejected;
      task.reject.forEach((reject) => reject(rejected));
      this.rejectPending(rejected);
    } finally {
      this.active = undefined;
      this.publish();
    }
    if (failure !== null && !this.disposed) this.onFailure(failure);
    void this.drain();
  }

  /**
   * Publish one snapshot of accepted work, including the executing invocation.
   */
  private publish(): void {
    this.onChange(
      this.disposed
        ? []
        : [...(this.active ? [this.active] : []), ...this.pending],
    );
  }

  /**
   * Settle queued promises without sending another request after a failure or removal.
   */
  private rejectPending(error: Error): void {
    this.pending
      .splice(0)
      .forEach((task) => task.reject.forEach((reject) => reject(error)));
  }
}
