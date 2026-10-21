export type LoadingMode = 'lazy' | 'defer' | 'manual';

/**
 * Schedules one initial load without retrying failures or surviving root disposal.
 *
 * Visibility controls automatic work only. Explicit sync remains available independently
 * and stops this trigger before submitting its own initialization request.
 */
export class LoadTrigger {
  protected readonly lifetime = new AbortController();
  protected readonly observer: IntersectionObserver | undefined;
  protected visible: boolean;
  protected disposed = false;

  /** Observe visibility or schedule startup work after the client has registered. */
  constructor(
    protected readonly root: HTMLElement,
    mode: LoadingMode,
    protected readonly load: () => Promise<unknown>,
  ) {
    this.visible =
      mode === 'defer' || typeof IntersectionObserver === 'undefined';
    if (mode === 'manual') return;
    if (mode === 'lazy' && typeof IntersectionObserver !== 'undefined') {
      this.observer = new IntersectionObserver((entries) => {
        for (const entry of entries) {
          if (entry.target === root) this.visible = entry.isIntersecting;
        }
        this.schedule();
      });
      this.observer.observe(root);
    }
    root.ownerDocument.addEventListener(
      'visibilitychange',
      () => this.schedule(),
      {
        signal: this.lifetime.signal,
      },
    );
    queueMicrotask(() => this.schedule());
  }

  /** Release scheduled work and observation before an explicit load or removal. */
  dispose(): void {
    this.disposed = true;
    this.lifetime.abort();
    this.observer?.disconnect();
  }

  /** Start once while connected and visible; the request owner handles all failures. */
  protected schedule(): void {
    if (
      this.disposed ||
      !this.visible ||
      !this.root.isConnected ||
      this.root.ownerDocument.visibilityState === 'hidden'
    )
      return;
    this.dispose();
    // The client returns a settled RequestResult, including failures.
    void this.load();
  }
}
