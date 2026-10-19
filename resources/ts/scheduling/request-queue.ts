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
  private running = false;
  private disposed = false;

  /**
   * Bind execution and error reporting without coupling scheduling to fetch or DOM APIs.
   */
  constructor(
    private readonly execute: (invocation: Invocation) => Promise<void>,
    private readonly onFailure: (error: Error) => void,
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
      void this.drain();
    });
  }

  /**
   * Reject unsent work; the owner remains responsible for aborting browser transport.
   */
  dispose(): void {
    this.disposed = true;
    this.rejectPending(new Error('Island was removed.'));
  }

  /**
   * Execute at most one invocation and propagate failures to every pending caller.
   */
  private async drain(): Promise<void> {
    if (this.running || this.disposed) return;
    const task = this.pending.shift();
    if (!task) return;
    this.running = true;
    try {
      await this.execute({ action: task.action, args: task.args });
      task.resolve.forEach((resolve) => resolve());
    } catch (error: unknown) {
      const failure =
        error instanceof Error ? error : new Error('Island request failed.');
      if (!this.disposed) this.onFailure(failure);
      task.reject.forEach((reject) => reject(failure));
      this.rejectPending(failure);
    } finally {
      this.running = false;
      void this.drain();
    }
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
