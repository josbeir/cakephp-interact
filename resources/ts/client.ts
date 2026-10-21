import { sameFields } from './url/codec';
import { pageUrl } from './url/coordinator';
import type { UrlRegistration } from './url/coordinator';
import type { UrlRestoration } from './url/types';
import { displaySize } from './uploads/size';
import { UploadFields } from './uploads/fields';
import { postUpload } from './uploads/transport';
import type { UploadJob, Uploader } from './uploads/types';
import { Controls } from './bindings/controls';
import type { Control } from './bindings/control-values';
import type { ModelBinding } from './bindings/model';
import type { CustomModel } from './bindings/custom-model';
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
  private readonly uploads: UploadFields;
  private readonly url: UrlRegistration | undefined;
  private urlTarget: UrlRestoration | undefined;

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
    private readonly uploader: Uploader = postUpload,
  ) {
    this.requests = new RequestActivity(reactive);
    this.activity = reactive({ paused: false });
    const activity = this.activity;
    this.metadata = readMetadata(root);
    this.snapshot = this.metadata.snapshot;
    this.baseline = clone(this.metadata.state);
    this.writable = this.metadata.writable;
    this.uploads = new UploadFields(
      this.metadata.uploads,
      reactive,
      (job) => this.request(null, [], job),
      (element) =>
        this.root.contains(element) &&
        element.closest('[data-interact-id]') === this.root,
    );
    const dirty = () =>
      this.uploads.pending() ||
      this.uploads.blocked() ||
      !equal(this.bridge.state, this.baseline);
    this.bridge = reactive({
      uploads: this.uploads.fields,
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
      (error) =>
        error instanceof IslandRequestError &&
        error.failure.code === 'cancelled',
    );
    this.controls = new Controls(
      root,
      this.bridge,
      () => this.writable,
      (cause) => this.failed(requestError('client_error', null, cause)),
    );
    this.url = Object.keys(this.metadata.url).length
      ? pageUrl(window).register(
          this.metadata.url,
          this.baseline,
          (target) => this.restoreUrl(target),
          (cause) => this.failed(requestError('client_error', null, cause)),
          root,
        )
      : undefined;
  }

  /**
   * Register a native model for the lifetime managed by its Alpine directive.
   */
  bindModel(control: Control, binding: ModelBinding): () => void {
    if (control instanceof HTMLInputElement && control.type === 'file')
      return this.uploads.bind(control, binding);
    return this.controls.bindModel(control, binding);
  }

  /**
   * Connect an application-owned Alpine value to the existing model coordinator.
   */
  bindCustomModel(
    element: HTMLElement,
    binding: ModelBinding,
    source: CustomModel,
  ): () => void {
    return this.controls.bindCustomModel(element, binding, source);
  }

  /**
   * Capture an observed custom value using the same failure feedback as native edits.
   */
  customChanged(element: HTMLElement): void {
    this.controls.customChanged(element);
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
    this.uploads.dispose();
    this.controls.dispose();
    this.queue.dispose();
    this.requests.dispose();
    this.url?.dispose();
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
    upload?: UploadJob,
  ): Promise<RequestResult> {
    try {
      if (this.bridge.paused && this.bridge.error?.recovery !== 'retry') {
        throw new IslandRequestError(
          this.bridge.error ?? requestError('client_error', null).failure,
        );
      }
      if (this.urlTarget && !upload) {
        await this.queue.enqueue({
          action: null,
          args: [],
          urlRestore: this.urlTarget,
          urlEpoch: this.urlTarget.epoch,
        });
      }
      await this.enqueue(action, args, upload);
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
  private enqueue(
    action: string | null,
    args: Json[],
    upload?: UploadJob,
  ): Promise<void> {
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
      if (!upload) this.uploads.captureAll();
    } catch (cause: unknown) {
      const error = requestError('client_error', null, cause);
      this.failed(error);
      return Promise.reject(error);
    }
    this.bridge.error = null;
    this.activity.paused = false;
    return this.queue.enqueue({
      ...(this.url ? { urlEpoch: this.url.epoch() } : {}),
      action,
      args,
      ...(upload ? { upload } : { uploadRevision: this.uploads.revision }),
    });
  }

  /**
   * Submit the current draft and apply a verified response while the island still exists.
   */
  private async execute(invocation: Invocation): Promise<void> {
    const upload = invocation.upload;
    if (
      upload
        ? !this.uploads.start(upload)
        : !invocation.urlRestore &&
          (this.uploads.blocked() ||
            invocation.uploadRevision !== this.uploads.revision)
    )
      throw requestError('cancelled', null);
    if (upload?.kind === 'stage') {
      const maximum = this.uploads.maximum(upload.field);
      const full = this.uploads.full(upload.field);
      let message: string | undefined;
      if (full) message = 'Remove an attachment before adding another file.';
      else if (maximum !== undefined && upload.file.size > maximum)
        message = `Choose a file no larger than ${displaySize(maximum)}.`;
      if (message !== undefined) {
        this.bridge.errors = {
          ...this.bridge.errors,
          [upload.field]: [message],
        };
        throw new IslandRequestError({
          ...requestError(full ? 'invalid_request' : 'upload_too_large', null)
            .failure,
          message,
        });
      }
    }
    this.bridge.busy = true;
    this.root.setAttribute('aria-busy', 'true');
    try {
      const restoration = invocation.urlRestore;
      const sent = restoration
        ? { ...clone(this.baseline), ...clone(restoration.values) }
        : clone(this.bridge.state);
      const payload = {
        version: 1 as const,
        snapshot: this.snapshot,
        updates: restoration
          ? restoration.values
          : changedWritable(sent, this.baseline, this.writable),
        ...(window.location.search
          ? { pageQuery: window.location.search }
          : {}),
        ...(restoration ? { urlRestore: true } : {}),
        action: invocation.action,
        args: invocation.args,
        ...(upload
          ? {
              upload: {
                kind: upload.kind,
                field: upload.field,
                ...(upload.kind === 'remove' && upload.id !== undefined
                  ? { id: upload.id }
                  : {}),
              },
            }
          : {}),
      };
      const result =
        upload?.kind === 'stage'
          ? await this.uploader(
              this.metadata.endpoint,
              this.metadata.csrf,
              payload,
              upload.file,
              upload.signal,
              (value) => this.uploads.progress(upload, value),
            )
          : await postUpdate(
              this.metadata,
              payload,
              this.lifetime.signal,
              this.fetcher,
            );
      if (upload?.kind === 'stage' && upload.signal.aborted)
        throw requestError('cancelled', null);
      if (this.disposed) return;
      const accepted = await readResponse(result, this.metadata.id);
      if (this.disposed) return;
      if (!sameFields(accepted.url ?? {}, this.metadata.url))
        throw requestError('invalid_response', result.status);
      let replacement: HTMLElement;
      try {
        replacement = readRoot(accepted.html, this.metadata.id);
      } catch (cause: unknown) {
        throw requestError('invalid_response', result.status, cause);
      }
      try {
        this.apply(accepted, sent, replacement, upload, invocation);
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
    upload?: UploadJob,
    invocation?: Invocation,
  ): void {
    this.controls.captureCustom();
    const newer = changedWritable(this.bridge.state, sent, this.writable);
    if (this.urlTarget && invocation?.urlRestore?.epoch !== this.url?.epoch())
      Object.assign(newer, this.urlTarget.values);
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
      this.uploads.accept(payload.uploads ?? {}, upload);
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
    if (
      invocation?.urlRestore &&
      invocation.urlRestore.epoch === this.url?.epoch()
    )
      this.urlTarget = undefined;
    this.url?.accept(
      payload.state,
      invocation?.urlEpoch ?? 0,
      invocation?.urlRestore !== undefined,
    );
    for (const effect of payload.events)
      this.root.dispatchEvent(
        new CustomEvent(effect.name, { detail: effect.detail, bubbles: true }),
      );
  }

  /**
   * Restore one complete URL selection without capturing or discarding unrelated drafts.
   */
  private restoreUrl(target: UrlRestoration): void {
    if (this.disposed) return;
    this.controls.captureAll();
    const unchanged = Object.entries(target.values).every(
      ([key, value]) =>
        equal(value, this.bridge.state[key]) &&
        equal(value, this.baseline[key]),
    );
    if (unchanged && !this.bridge.pending()) return;
    this.controls.clearPending();
    this.urlTarget = target;
    this.bridge.state = { ...this.bridge.state, ...clone(target.values) };
    this.controls.refresh();
    if (this.bridge.paused && this.bridge.error?.recovery !== 'retry') return;
    this.bridge.error = null;
    this.activity.paused = false;
    void this.queue
      .enqueue({
        action: null,
        args: [],
        urlRestore: target,
        urlEpoch: target.epoch,
      })
      .catch(() => {
        // The queue owns failure feedback. Keep the selected target available for explicit recovery.
      });
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
