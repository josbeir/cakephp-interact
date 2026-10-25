import type { IslandBridge } from '../types';
import type { RealtimeCoordinator } from './coordinator';
import type {
  PushMessage,
  RealtimeMetadata,
  RealtimeOwner,
  Subscription,
} from './types';

/**
 * Tracks one island's invalidations without sending drafts or erasing validation feedback.
 *
 * The existing request lifecycle clears stale generations only after authoritative
 * rendering. Provider notifications and explicit display updates remain independent.
 */
export class RealtimeIsland implements RealtimeOwner {
  private membership: ReturnType<RealtimeCoordinator['join']> | undefined;
  private endpoint: string | undefined;
  private generation = 0;
  private timer: ReturnType<typeof setTimeout> | undefined;
  private running = false;
  private disposed = false;
  private readonly lifetime = new AbortController();
  readonly id: string;
  readonly feedback;

  constructor(
    private readonly root: HTMLElement,
    private readonly bridge: IslandBridge,
    private readonly coordinator: RealtimeCoordinator,
    private readonly eligible: () => boolean,
  ) {
    this.id = root.dataset.interactId ?? '';
    this.feedback = bridge.realtime;
    root.ownerDocument.addEventListener(
      'visibilitychange',
      () => this.flush(),
      { signal: this.lifetime.signal },
    );
  }

  /** Reconcile server-owned declarations after successful renders and renderless updates. */
  update(metadata: RealtimeMetadata | undefined, csrf: string): void {
    if (this.disposed) return;
    const endpoint = metadata
      ? JSON.stringify([metadata.endpoint, csrf])
      : undefined;
    if (endpoint !== this.endpoint || !metadata?.subscriptions.length) {
      this.membership?.dispose();
      this.membership = undefined;
      this.endpoint = endpoint;
    }
    if (metadata?.subscriptions.length) {
      if (this.membership) this.membership.update(metadata);
      else this.membership = this.coordinator.join(this, metadata, csrf);
    } else {
      this.feedback.status = 'disabled';
      this.feedback.error = null;
      this.feedback.stale = false;
    }
  }

  /** Retain renewed proofs when a compatible layout survives navigation. */
  refreshContext(csrf: string): void {
    if (this.membership) this.update(this.membership.current(), csrf);
  }

  /** Deliver each declared behavior once even when event filters overlap. */
  receive(message: PushMessage, matches: Subscription[]): void {
    if (this.disposed) return;
    const modes = new Set(matches.map((entry) => entry.mode));
    if (modes.has('refresh')) this.invalidate();
    if (modes.has('event'))
      this.root.dispatchEvent(
        new CustomEvent('interact:push', {
          detail: {
            id: message.id,
            topic: message.topic,
            event: message.event,
            detail: message.detail,
          },
          bubbles: true,
        }),
      );
    if (modes.has('stream') && message.display)
      this.bridge.receiveStream({
        ...message.display,
        version: 1,
        id: this.id,
      });
  }

  /** Retain invalidations until the island can safely render accepted application state. */
  invalidate(): void {
    this.generation++;
    this.feedback.stale = true;
    this.flush();
  }

  /** Recheck eligibility from Alpine effects and lifecycle transitions without polling. */
  flush(): void {
    if (this.disposed || !this.feedback.stale) return;
    // Keep Alpine subscribed to eligibility while a timer or refresh is active.
    // A busy transition must wake an invalidation whose timer could not run.
    const eligible = this.eligible();
    if (
      this.running ||
      this.timer ||
      this.root.ownerDocument.visibilityState === 'hidden' ||
      !eligible
    )
      return;
    this.timer = setTimeout(() => {
      this.timer = undefined;
      if (
        this.disposed ||
        this.root.ownerDocument.visibilityState === 'hidden' ||
        !this.eligible()
      )
        return;
      this.running = true;
      void this.bridge.sync().finally(() => {
        this.running = false;
        this.flush();
      });
    }, 100);
  }

  /** Capture the invalidation generation before an ordinary HTTP request begins. */
  begin(): number {
    return this.generation;
  }

  /** Renderless results and changes arriving after request start cannot clear stale status. */
  accepted(rendered: boolean, generation: number | undefined): void {
    if (rendered && generation === this.generation) this.feedback.stale = false;
    this.flush();
  }

  /** Release subscription ownership and ignore late asynchronous delivery. */
  dispose(): void {
    this.disposed = true;
    clearTimeout(this.timer);
    this.lifetime.abort();
    this.membership?.dispose();
  }
}
