import { Controls } from './bindings/controls';
import { readRoot } from './dom/markup';
import { preserveSelection } from './dom/selection';
import { RequestQueue } from './scheduling/request-queue';
import { changedWritable } from './state/drafts';
import { clone, equal } from './state/values';
import { readMetadata } from './transport/metadata';
import type { MountMetadata } from './transport/metadata';
import { postUpdate, readResponse } from './transport/request';
import type {
  Fetcher,
  Invocation,
  IslandBridge,
  IslandResponse,
  Json,
  Patcher,
  State,
  Writable,
} from './types';

/**
 * Coordinates browser drafts and server renders for one interactive island.
 *
 * Bindings own native controls, the queue owns request order, and transport validates
 * server envelopes. This coordinator reconciles accepted state with newer local edits
 * and exposes the reactive bridge used by Alpine's `$server` magic.
 */
export class IslandClient {
  readonly bridge: IslandBridge;
  private readonly metadata: MountMetadata;
  private readonly controls: Controls;
  private readonly queue: RequestQueue;
  private readonly lifetime = new AbortController();
  private snapshot: string;
  private baseline: State;
  private writable: Writable;
  private disposed = false;

  /**
   * Initialize the bridge and request-scoped collaborators from PHP-rendered metadata.
   *
   * @param root The DOM root owned by this island.
   * @param patch A morph operation that preserves the root and independently owned children.
   * @param reactive The reactivity wrapper provided by the application's Alpine instance.
   * @param fetcher The replaceable HTTP boundary, defaulting to native fetch.
   */
  constructor(
    readonly root: HTMLElement,
    private readonly patch: Patcher,
    reactive: <T extends object>(value: T) => T,
    private readonly fetcher: Fetcher = (url, init) => fetch(url, init),
  ) {
    this.metadata = readMetadata(root);
    this.snapshot = this.metadata.snapshot;
    this.baseline = clone(this.metadata.state);
    this.writable = this.metadata.writable;
    const dirty = () => !equal(this.bridge.state, this.baseline);
    this.bridge = reactive({
      state: this.metadata.state,
      busy: false,
      errors: {},
      error: null,
      get dirty() {
        return dirty();
      },
      sync: () => this.enqueue(null, []),
      call: (action: string, ...args: Json[]) => this.enqueue(action, args),
    });
    this.queue = new RequestQueue(
      (invocation) => this.execute(invocation),
      (error) => this.failed(error),
    );
    this.controls = new Controls(root, this.bridge, () => this.writable);
  }

  /**
   * Dispose owned bindings and unsent work, and abort the browser's active fetch.
   *
   * Aborting fetch does not establish whether the server executed an action.
   */
  dispose(): void {
    this.disposed = true;
    this.lifetime.abort();
    this.controls.dispose();
    this.queue.dispose();
  }

  /**
   * Flush scheduled debounce timers before routing all bridge calls through one queue.
   */
  private enqueue(action: string | null, args: Json[]): Promise<void> {
    this.controls.clearPending();
    return this.queue.enqueue({ action, args });
  }

  /**
   * Submit the current draft and apply a verified response while the island still exists.
   */
  private async execute(invocation: Invocation): Promise<void> {
    this.bridge.busy = true;
    this.bridge.error = null;
    this.root.setAttribute('aria-busy', 'true');
    try {
      const sent = clone(this.bridge.state);
      const result = await postUpdate(
        this.metadata,
        {
          version: 1,
          snapshot: this.snapshot,
          updates: changedWritable(sent, this.baseline, this.writable),
          ...invocation,
        },
        this.lifetime.signal,
        this.fetcher,
      );
      if (this.disposed) return;
      const payload = await readResponse(result, this.metadata.id);
      if (!this.disposed) this.apply(payload, sent);
    } finally {
      this.bridge.busy = false;
      this.root.removeAttribute('aria-busy');
    }
  }

  /**
   * Morph accepted markup, restore newer drafts and selection, then dispatch response effects.
   */
  private apply(payload: IslandResponse, sent: State): void {
    const replacement = readRoot(payload.html, this.metadata.id);
    const newer = changedWritable(this.bridge.state, sent, this.writable);
    const restoreSelection = preserveSelection(this.root);
    this.patch(this.root, replacement);
    this.snapshot = payload.snapshot;
    this.baseline = clone(payload.state);
    this.writable = payload.writable;
    this.bridge.state = { ...payload.state, ...newer };
    this.bridge.errors = payload.errors;
    this.controls.refresh();
    restoreSelection();
    for (const effect of payload.events)
      this.root.dispatchEvent(
        new CustomEvent(effect.name, { detail: effect.detail, bubbles: true }),
      );
  }

  /**
   * Publish a request failure through reactive feedback and a native browser event.
   */
  private failed(error: Error): void {
    this.bridge.error = error.message;
    this.root.dispatchEvent(
      new CustomEvent('interact:error', {
        detail: { message: error.message },
        bubbles: true,
      }),
    );
  }
}
