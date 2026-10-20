import { Controls } from './bindings/controls';
import type { Control } from './bindings/control-values';
import type { ModelBinding } from './bindings/model';
import type { PollBinding } from './bindings/poll';
import { readRoot } from './dom/markup';
import { preserveSelection } from './dom/selection';
import { RequestActivity } from './scheduling/request-activity';
import { copyArguments } from './scheduling/targets';
import { RequestQueue } from './scheduling/request-queue';
import { Poller } from './scheduling/poller';
import { changedWritable } from './state/drafts';
import { clone, equal } from './state/values';
import { readMetadata } from './transport/metadata';
import type { MountMetadata } from './transport/metadata';
import { postUpdate, readResponse } from './transport/request';
import { IslandRequestError, requestError } from './transport/failure';
import type {
  Fetcher,
  Invocation,
  IslandBridge,
  IslandResponse,
  Json,
  Patcher,
  RequestResult,
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
  private readonly requests: RequestActivity;
  private readonly lifetime = new AbortController();
  private snapshot: string;
  private baseline: State;
  private writable: Writable;
  private disposed = false;
  private readonly activity: { paused: boolean };
  private poller: Poller | undefined;

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
    this.requests = new RequestActivity(reactive);
    this.activity = reactive({ paused: false });
    const activity = this.activity;
    this.metadata = readMetadata(root);
    this.snapshot = this.metadata.snapshot;
    this.baseline = clone(this.metadata.state);
    this.writable = this.metadata.writable;
    const dirty = () => !equal(this.bridge.state, this.baseline);
    this.bridge = reactive({
      state: this.metadata.state,
      busy: false,
      pending: (selector, ...args) => this.requests.pending(selector, ...args),
      loading: (selector, ...args) => this.requests.loading(selector, ...args),
      errors: {},
      error: null,
      get paused() {
        return activity.paused;
      },
      exportDraft: () =>
        JSON.stringify(
          changedWritable(this.bridge.state, {}, this.writable),
          null,
          2,
        ),
      get dirty() {
        return dirty();
      },
      sync: () => this.request(null, []),
      call: (action: string, ...args: Json[]) => this.request(action, args),
    });
    this.queue = new RequestQueue(
      (invocation) => this.execute(invocation),
      (error) => this.failed(error),
      (requests) => this.requests.update(requests),
    );
    this.controls = new Controls(
      root,
      this.bridge,
      () => this.writable,
      (cause) => this.failed(requestError('client_error', null, cause)),
    );
  }

  /**
   * Register a native model for the lifetime managed by its Alpine directive.
   */
  bindModel(control: Control, binding: ModelBinding): () => void {
    return this.controls.bindModel(control, binding);
  }

  /**
   * Register a scoped submission expression without exposing scheduling services.
   */
  bindSubmit(
    form: HTMLFormElement,
    attribute: string,
    expression: string,
    evaluate: (event: Event) => unknown,
  ): () => void {
    return this.controls.bindSubmit(form, attribute, expression, evaluate);
  }

  /**
   * Register one managed polling declaration within this island's ownership boundary.
   */
  bindPoll(
    element: HTMLElement,
    binding: PollBinding,
    evaluate: () => unknown,
  ): () => void {
    if (this.poller)
      throw new Error('Use one x-interact:poll declaration per island.');
    const poller = new Poller(
      element,
      binding,
      () => this.canPoll(element),
      evaluate,
      (cause) => this.failed(requestError('client_error', null, cause)),
    );
    this.poller = poller;
    return () => {
      poller.dispose();
      if (this.poller === poller) this.poller = undefined;
    };
  }

  /**
   * Dispose owned bindings and unsent work, and abort the browser's active fetch.
   *
   * Aborting fetch does not establish whether the server executed an action.
   */
  dispose(): void {
    this.disposed = true;
    this.lifetime.abort();
    this.poller?.dispose();
    this.controls.dispose();
    this.queue.dispose();
    this.requests.dispose();
  }

  /**
   * Preserve explicit edits and feedback before allowing a background expression to run.
   */
  private canPoll(element: HTMLElement): boolean {
    if (
      this.disposed ||
      !element.isConnected ||
      !this.root.contains(element) ||
      element.closest('[data-interact-id]') !== this.root ||
      this.bridge.paused ||
      this.bridge.pending() ||
      this.controls.hasPending() ||
      Object.values(this.bridge.errors).some((messages) => messages.length > 0)
    )
      return false;
    this.controls.captureAll();
    return !this.bridge.dirty;
  }

  /**
   * Settle application calls without requiring error callbacks in ordinary templates.
   *
   * The queue publishes failures before this result settles. Detached islands report
   * cancellation only to their callers, without resurrecting removed UI feedback.
   */
  private async request(
    action: string | null,
    args: Json[],
  ): Promise<RequestResult> {
    try {
      await this.enqueue(action, args);
      if (!this.disposed) return { ok: true };
    } catch (cause: unknown) {
      if (!this.disposed) {
        const error =
          cause instanceof IslandRequestError
            ? cause
            : requestError('client_error', null, cause);
        return { ok: false, error: error.failure };
      }
    }
    return { ok: false, error: requestError('cancelled', null).failure };
  }

  /**
   * Flush scheduled debounce timers before routing all bridge calls through one queue.
   */
  private enqueue(action: string | null, args: Json[]): Promise<void> {
    this.controls.clearPending();
    if (this.disposed) return Promise.reject(new Error('Island was removed.'));
    if (this.bridge.paused && this.bridge.error?.recovery !== 'retry')
      return Promise.reject(
        new IslandRequestError(
          this.bridge.error ?? requestError('client_error', null).failure,
        ),
      );
    try {
      args = copyArguments(args);
      this.controls.captureAll();
    } catch (cause: unknown) {
      const error = requestError('client_error', null, cause);
      this.failed(error);
      return Promise.reject(error);
    }
    this.bridge.error = null;
    this.activity.paused = false;
    return this.queue.enqueue({ action, args });
  }

  /**
   * Submit the current draft and apply a verified response while the island still exists.
   */
  private async execute(invocation: Invocation): Promise<void> {
    this.bridge.busy = true;
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
      if (this.disposed) return;
      let replacement: HTMLElement;
      try {
        replacement = readRoot(payload.html, this.metadata.id);
      } catch (cause: unknown) {
        throw requestError('invalid_response', result.status, cause);
      }
      try {
        this.apply(payload, sent, replacement);
      } catch (cause: unknown) {
        throw requestError('client_error', result.status, cause);
      }
    } catch (cause: unknown) {
      if (cause instanceof IslandRequestError) throw cause;
      throw requestError('client_error', null, cause);
    } finally {
      this.bridge.busy = false;
      this.root.removeAttribute('aria-busy');
    }
  }

  /**
   * Morph accepted markup, restore newer drafts and selection, then dispatch response effects.
   */
  private apply(
    payload: IslandResponse,
    sent: State,
    replacement: HTMLElement,
  ): void {
    const newer = changedWritable(this.bridge.state, sent, this.writable);
    const restoreSelection = preserveSelection(this.root);
    const previous = {
      state: this.bridge.state,
      errors: this.bridge.errors,
      snapshot: this.snapshot,
      baseline: this.baseline,
      writable: this.writable,
    };
    try {
      this.controls.beforeMorph();
      this.patch(this.root, replacement);
      this.snapshot = payload.snapshot;
      this.baseline = clone(payload.state);
      this.writable = payload.writable;
      this.bridge.state = { ...payload.state, ...newer };
      this.bridge.errors = payload.errors;
      this.controls.refresh();
      restoreSelection();
    } catch (cause: unknown) {
      this.bridge.state = previous.state;
      this.bridge.errors = previous.errors;
      this.snapshot = previous.snapshot;
      this.baseline = previous.baseline;
      this.writable = previous.writable;
      this.controls.refresh();
      throw cause;
    }
    for (const effect of payload.events)
      this.root.dispatchEvent(
        new CustomEvent(effect.name, { detail: effect.detail, bubbles: true }),
      );
  }

  /**
   * Publish a request failure through reactive feedback and a native browser event.
   */
  private failed(error: Error): void {
    this.controls.clearPending();
    this.activity.paused = true;
    const failure =
      error instanceof IslandRequestError
        ? error.failure
        : requestError('client_error', null, error).failure;
    this.bridge.error = failure;
    this.root.dispatchEvent(
      new CustomEvent('interact:error', {
        detail: { ...failure },
        bubbles: true,
      }),
    );
  }
}
