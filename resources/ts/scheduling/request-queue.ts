import type { Invocation } from '../types';
import type { RequestLifecycle } from '../diagnostics/request-lifecycle';

/**
 * Retains completion handlers for an action or a coalesced synchronization request.
 */
type Pending = Invocation & {
  resolve: (() => void)[];
  reject: ((error: Error) => void)[];
  lifecycle: RequestLifecycle | undefined;
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
  private notifications = 0;

  /**
   * Bind execution and error reporting without coupling scheduling to fetch or DOM APIs.
   */
  constructor(
    private readonly execute: (
      invocation: Invocation,
      lifecycle?: RequestLifecycle,
    ) => Promise<void>,
    private readonly onFailure: (error: Error) => void,
    private readonly onChange: (requests: readonly Invocation[]) => void,
    private readonly continueAfterFailure: (error: Error) => boolean = () =>
      false,
  ) {}

  /**
   * Queue an invocation and settle its promise after execution and response effects.
   */
  enqueue(
    invocation: Invocation,
    createLifecycle?: () => RequestLifecycle,
  ): Promise<void> {
    if (this.disposed) return Promise.reject(new Error('Island was removed.'));
    return new Promise((resolve, reject) => {
      const existing =
        invocation.urlRestore !== undefined
          ? this.pending.find((task) => task.urlRestore !== undefined)
          : invocation.action === null && invocation.upload === undefined
            ? this.pending.find(
                (task) =>
                  task.action === null &&
                  task.urlRestore === undefined &&
                  task.upload === undefined &&
                  task.uploadRevision === invocation.uploadRevision,
              )
            : undefined;
      this.notifications++;
      try {
        if (existing) {
          if (invocation.urlRestore) {
            existing.urlRestore = invocation.urlRestore;
            existing.urlEpoch = invocation.urlRestore.epoch;
          }
          existing.resolve.push(resolve);
          existing.reject.push(reject);
        } else {
          const task: Pending = {
            ...invocation,
            resolve: [resolve],
            reject: [reject],
            lifecycle: createLifecycle?.(),
          };
          this.pending.push(task);
          this.publish();
          task.lifecycle?.queued();
        }
        if (existing) this.publish();
      } finally {
        this.notifications--;
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
    this.publish();
  }

  /**
   * Execute at most one invocation and propagate failures to every pending caller.
   */
  private async drain(): Promise<void> {
    if (this.active || this.disposed || this.notifications) return;
    const task = this.pending.shift();
    if (!task) return;
    this.active = task;
    let failure: Error | null = null;
    let discarded: Pending[] = [];
    try {
      task.lifecycle?.started();
      if (!this.disposed)
        await this.execute(
          {
            action: task.action,
            args: task.args,
            ...(task.event === undefined ? {} : { event: task.event }),
            ...(task.navigationEpoch === undefined
              ? {}
              : { navigationEpoch: task.navigationEpoch }),
            ...(task.urlEpoch === undefined ? {} : { urlEpoch: task.urlEpoch }),
            ...(task.urlRestore ? { urlRestore: task.urlRestore } : {}),
            ...(task.upload ? { upload: task.upload } : {}),
            ...(task.uploadRevision === undefined
              ? {}
              : { uploadRevision: task.uploadRevision }),
          },
          task.lifecycle,
        );
    } catch (error: unknown) {
      const rejected =
        error instanceof Error ? error : new Error('Island request failed.');
      failure = rejected;
      if (this.disposed) task.lifecycle?.result('cancelled');
      else task.lifecycle?.failed(rejected);
      if (!this.continueAfterFailure(rejected))
        discarded = this.pending.splice(0);
    }
    this.notifications++;
    try {
      this.active = undefined;
      this.publish();
      if (
        failure !== null &&
        !this.disposed &&
        !this.continueAfterFailure(failure)
      )
        this.onFailure(failure);
      task.lifecycle?.finish(this.disposed);
      if (failure) {
        const rejected = failure;
        task.reject.forEach((reject) => reject(rejected));
        for (const pending of discarded) {
          pending.lifecycle?.finish(true);
          pending.reject.forEach((reject) => reject(rejected));
        }
      } else {
        task.resolve.forEach((resolve) => resolve());
      }
    } finally {
      this.notifications--;
    }
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
    this.pending.splice(0).forEach((task) => {
      task.lifecycle?.finish(true);
      task.reject.forEach((reject) => reject(error));
    });
  }
}
