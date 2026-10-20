import type { PollBinding } from '../bindings/poll';

/**
 * Runs one island's polling expression without overlapping or accumulating ticks.
 *
 * Visibility owns timer suspension; the client decides whether drafts and request
 * feedback permit a tick. Disposal never cancels an already accepted server operation.
 */
export class Poller {
  private readonly lifetime = new AbortController();
  private readonly observer: IntersectionObserver | undefined;
  private timer: ReturnType<typeof setTimeout> | undefined;
  private intersecting: boolean;
  private running = false;
  private disposed = false;

  /**
   * Observe the declaration's visibility and wait one interval before its first tick.
   */
  constructor(
    private readonly element: HTMLElement,
    private readonly binding: PollBinding,
    private readonly eligible: () => boolean,
    private readonly invoke: () => unknown,
    private readonly onFailure: (cause: unknown) => void,
  ) {
    this.intersecting = !binding.visible;
    if (binding.visible) {
      this.observer = new IntersectionObserver((entries) => {
        for (const entry of entries) {
          if (entry.target !== element) continue;
          this.intersecting = entry.isIntersecting;
          this.schedule();
        }
      });
      this.observer.observe(element);
    }
    element.ownerDocument.addEventListener(
      'visibilitychange',
      () => this.schedule(),
      { signal: this.lifetime.signal },
    );
    this.schedule();
  }

  /**
   * Release timers and visibility resources without reviving them on late completion.
   */
  dispose(): void {
    this.disposed = true;
    this.lifetime.abort();
    this.observer?.disconnect();
    clearTimeout(this.timer);
  }

  /**
   * Begin a fresh interval only while the declaration can be seen and no tick is running.
   */
  private schedule(): void {
    clearTimeout(this.timer);
    this.timer = undefined;
    if (
      this.disposed ||
      this.running ||
      !this.intersecting ||
      this.element.ownerDocument.visibilityState === 'hidden'
    )
      return;
    this.timer = setTimeout(() => {
      void this.tick();
    }, this.binding.interval);
  }

  /**
   * Skip blocked work or await the expression before starting its next interval.
   */
  private async tick(): Promise<void> {
    this.timer = undefined;
    this.running = true;
    try {
      if (this.eligible()) await this.invoke();
    } catch (cause: unknown) {
      if (!this.disposed) this.onFailure(cause);
    } finally {
      this.running = false;
      this.schedule();
    }
  }
}
