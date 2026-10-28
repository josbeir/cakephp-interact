import { ownsIslandElement } from './dom/ownership';
import { ActionEvents } from './events/actions';
import { RealtimeIsland } from './realtime/island';
import type { RealtimeCoordinator } from './realtime/coordinator';
import { StreamReceiver } from './streaming/receiver';
import { paginationLinks } from './pagination/links';
import { navigation, enhancedRedirect } from './navigation/registry';
import { LoadTrigger } from './loading/trigger';
import { BrowserEffects } from './effects/browser';
import { sameFields } from './url/codec';
import { pageUrl } from './url/coordinator';
import type { UrlRegistration } from './url/coordinator';
import type { UrlRestoration } from './url/types';
import { displaySize } from './uploads/size';
import { UploadFields } from './uploads/fields';
import { postUpload } from './uploads/transport';
import type { UploadJob, Uploader, UploadMetadata } from './uploads/types';
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
import { RequestLifecycle } from './diagnostics/request-lifecycle';
import type { RequestKind } from './diagnostics/types';
import { Poller } from './scheduling/poller';
import { changedWritable, newerWritable } from './state/drafts';
import { clone, equal } from './state/values';
import { readMetadata } from './transport/metadata';
import type { MountMetadata } from './transport/metadata';
import type { CompositionHooks } from './composition/types';
import { mergeChanges, read } from './state/paths';
import { postUpdate, readResponse } from './transport/request';
import { IslandRequestError, requestError } from './transport/failure';
import type {
  Fetcher,
  Invocation,
  IslandBridge,
  IslandResponse,
  RenderlessResponse,
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
  /** @internal Parent/child lifecycle integration, installed with the Alpine plugin. */
  composition: CompositionHooks | undefined;
  private parentTicket: string | undefined;
  readonly bridge: IslandBridge;
  readonly streams: StreamReceiver;
  private readonly metadata: MountMetadata;
  private readonly controls: Controls;
  private readonly queue: RequestQueue;
  private readonly requests: RequestActivity;
  private readonly lifetime = new AbortController();
  private readonly disposePagination: () => void;
  private snapshot: string;
  private mounted: boolean;
  private initializing: Promise<RequestResult> | undefined;
  private trigger: LoadTrigger | undefined;
  private baseline: State;
  private writable: Writable;
  private disposed = false;
  private leaving = false;
  private readonly activity: { paused: boolean };
  private poller: Poller | undefined;
  private realtime: RealtimeIsland | undefined;
  private actionEvents: ActionEvents | undefined;
  private uploads: UploadFields;
  private readonly url: UrlRegistration | undefined;
  private urlTarget: UrlRestoration | undefined;

  /** Connect subscription ownership to this installation's shared provider connections. */
  attachRealtime(coordinator: RealtimeCoordinator): void {
    this.realtime = new RealtimeIsland(
      this.root,
      this.bridge,
      coordinator,
      () =>
        !this.urlTarget &&
        !this.leaving &&
        !navigation(window)?.activity?.store.pending &&
        this.canPoll(this.root),
    );
    this.realtime.update(this.metadata.realtime, this.metadata.csrf);
  }

  /** Recheck background refresh eligibility from the installation's Alpine effect. */
  flushRealtime(): void {
    this.realtime?.flush();
  }

  /** @internal Read loading status without exposing initialization controls on the public bridge. */
  isMounted(): boolean {
    return this.mounted;
  }

  /** @internal Check explicit writable ownership before connecting a model source or target. */
  canModel(path: string): boolean {
    const separator = path.indexOf('.');
    const owner = separator < 0 ? path : path.slice(0, separator);
    return (this.writable[path] ?? this.writable[owner]) !== undefined;
  }

  /** @internal Read the last accepted value for cross-island draft reconciliation. */
  modelBaseline(path: string): Json | undefined {
    return read(this.baseline, path);
  }

  /** @internal Capture bound controls synchronously before an owning parent's submission. */
  captureModels(): void {
    this.controls.captureAll();
    this.composition?.capture();
  }

  /** @internal Refresh native controls after a shared draft changes. */
  refreshModels(): void {
    this.controls.refresh();
  }

  /** @internal Retain the latest capability until a child request accepts that exact ticket. */
  receiveParentInputs(ticket: string): void {
    if (this.disposed || this.leaving) return;
    this.parentTicket = ticket;
    if (this.mounted && !this.bridge.paused) void this.bridge.sync();
  }

  /** @internal Route malformed relationships through ordinary client feedback. */
  compositionFailed(cause: unknown): void {
    this.failed(requestError('client_error', null, cause));
  }

  /** Refresh request context after a compatible layout region survives a page visit. */
  refreshContext(incoming: HTMLElement): void {
    this.metadata.csrf = incoming.dataset.interactCsrf ?? '';
    this.root.dataset.interactCsrf = this.metadata.csrf;
    this.realtime?.refreshContext(this.metadata.csrf);
  }

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
    private readonly reactive: <T extends object>(value: T) => T,
    private readonly fetcher: Fetcher = (url, init) => fetch(url, init),
    private readonly uploader: Uploader = postUpload,
    private readonly effects: BrowserEffects = new BrowserEffects(),
    readonly defaults: TimingDefaults = defaultTiming,
  ) {
    this.streams = new StreamReceiver(
      root,
      () => !this.disposed && !this.leaving,
    );
    this.requests = new RequestActivity(reactive, defaults.loadingDelay);
    this.activity = reactive({ paused: false });
    const activity = this.activity;
    this.metadata = readMetadata(root);
    this.snapshot = this.metadata.snapshot;
    this.mounted = this.metadata.loading === undefined;
    this.baseline = clone(this.metadata.state);
    this.writable = this.metadata.writable;
    this.uploads = this.createUploads(this.metadata.uploads);
    const dirty = () =>
      this.uploads.pending() ||
      this.uploads.blocked() ||
      !equal(this.bridge.state, this.baseline);
    this.bridge = reactive({
      realtime: { status: 'disabled', stale: false, error: null },
      uploads: this.uploads.fields,
      state: this.metadata.state,
      busy: false,
      pending: (selector, ...args) => this.requests.pending(selector, ...args),
      loading: (selector, ...args) => this.requests.loading(selector, ...args),
      errors: this.metadata.errors,
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
      receiveStream: (message: unknown) => this.streams.receive(message),
      sync: () => this.request(null, []),
      call: (action: string, ...args: Json[]) => this.request(action, args),
    });
    this.queue = new RequestQueue(
      (invocation, lifecycle) => this.execute(invocation, lifecycle),
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
          this.mounted ? this.baseline : undefined,
          (target) => this.restoreUrl(target),
          (cause) => this.failed(requestError('client_error', null, cause)),
          root,
        )
      : undefined;
    this.disposePagination = paginationLinks(
      root,
      () => this.writable,
      (changes) => {
        this.controls.captureAll();
        this.controls.clearPending();
        this.bridge.state = mergeChanges(this.bridge.state, changes);
        this.controls.refresh();
        void this.bridge.sync();
      },
      (cause) => this.failed(requestError('client_error', null, cause)),
    );
    if (this.metadata.loading !== undefined)
      this.trigger = new LoadTrigger(root, this.metadata.loading, () =>
        this.request(null, []),
      );
    if (this.mounted) this.listenForEvents();
  }

  /**
   * Build transfer handles from declarations available only after a real mount.
   */
  private createUploads(metadata: UploadMetadata): UploadFields {
    return new UploadFields(
      metadata,
      this.reactive,
      (job) => this.request(null, [], job),
      (element) => ownsIslandElement(this.root, element),
    );
  }

  /**
   * Register a native model for the lifetime managed by its Alpine directive.
   */
  bindModel(control: Control, binding: ModelBinding): () => void {
    if (!this.mounted) throw new Error('Models require a loaded island.');
    if (control instanceof HTMLInputElement && control.type === 'file')
      return this.uploads.bind(control, binding);
    return this.controls.bindModel(control, binding);
  }

  /**
   * Bind native names to an array draft without changing submission behavior.
   */
  bindForm(
    form: HTMLFormElement,
    binding: ModelBinding,
    prefix: string,
  ): () => void {
    if (!this.mounted) throw new Error('Models require a loaded island.');
    return this.controls.bindForm(form, binding, prefix);
  }

  /**
   * Connect an application-owned Alpine value to the existing model coordinator.
   */
  bindCustomModel(
    element: HTMLElement,
    binding: ModelBinding,
    source: CustomModel,
  ): () => void {
    if (!this.mounted) throw new Error('Models require a loaded island.');
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
    if (!this.mounted) throw new Error('Polling requires a loaded island.');
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
    this.actionEvents?.dispose();
    this.composition?.dispose();
    this.disposed = true;
    this.streams.dispose();
    this.realtime?.dispose();
    this.lifetime.abort();
    this.disposePagination();
    this.trigger?.dispose();
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
      !ownsIslandElement(this.root, element) ||
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
  private request(
    action: string | null,
    args: Json[],
    upload?: UploadJob,
    event?: string,
  ): Promise<RequestResult> {
    if (this.mounted) return this.sendRequest(action, args, upload, event);
    if (action !== null || args.length || upload !== undefined) {
      const error = requestError('client_error', null);
      if (!this.disposed) this.failed(error);
      return Promise.resolve({ ok: false, error: error.failure });
    }
    if (this.initializing) return this.initializing;
    this.trigger?.dispose();
    this.initializing = this.sendRequest(null, []).finally(() => {
      this.initializing = undefined;
    });
    return this.initializing;
  }

  /**
   * Route a synchronization or action through the request queue and settled feedback.
   */
  private async sendRequest(
    action: string | null,
    args: Json[],
    upload?: UploadJob,
    event?: string,
  ): Promise<RequestResult> {
    const navigationEpoch = navigation(window)?.epoch();
    try {
      if (this.leaving)
        return { ok: false, error: requestError('cancelled', null).failure };
      if (this.bridge.paused && this.bridge.error?.recovery !== 'retry') {
        throw new IslandRequestError(
          this.bridge.error ?? requestError('client_error', null).failure,
        );
      }
      if (this.mounted && this.urlTarget && !upload) {
        await this.enqueueTask({
          action: null,
          args: [],
          urlRestore: this.urlTarget,
          urlEpoch: this.urlTarget.epoch,
        });
      }
      await this.enqueue(action, args, upload, navigationEpoch, event);
      if (!this.disposed) return { ok: true };
    } catch (cause: unknown) {
      if (this.leaving)
        return { ok: false, error: requestError('cancelled', null).failure };
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
    navigationEpoch?: number,
    event?: string,
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
      this.composition?.capture();
      if (!upload) this.uploads.captureAll();
      this.composition?.flush(this.bridge.state);
    } catch (cause: unknown) {
      const error = requestError('client_error', null, cause);
      this.failed(error);
      return Promise.reject(error);
    }
    this.bridge.error = null;
    this.activity.paused = false;
    return this.enqueueTask({
      ...(navigationEpoch === undefined ? {} : { navigationEpoch }),
      ...(this.url ? { urlEpoch: this.url.epoch() } : {}),
      action,
      args,
      ...(event === undefined ? {} : { event }),
      ...(upload ? { upload } : { uploadRevision: this.uploads.revision }),
    });
  }

  /**
   * Submit the current draft and apply a verified response while the island still exists.
   */
  private async execute(
    invocation: Invocation,
    lifecycle?: RequestLifecycle,
  ): Promise<void> {
    const realtimeGeneration = this.realtime?.begin();
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
    const page = navigation(window);
    const pageEpoch = invocation.navigationEpoch ?? page?.epoch();
    page?.invalidate();
    this.bridge.busy = true;
    this.root.setAttribute('aria-busy', 'true');
    try {
      const restoration = invocation.urlRestore;
      const parentTicket = this.parentTicket;
      const sent = restoration
        ? mergeChanges(this.baseline, restoration.values)
        : clone(this.bridge.state);
      this.composition?.begin(invocation.action, sent);
      const payload = {
        version: 1 as const,
        ...(!this.mounted ? { initialize: true } : {}),
        snapshot: this.snapshot,
        updates: restoration
          ? restoration.values
          : {
              ...changedWritable(sent, this.baseline, this.writable),
              ...(parentTicket && this.mounted
                ? this.composition?.updates()
                : {}),
            },
        ...(parentTicket ? { parentInputs: parentTicket } : {}),
        ...(window.location.search
          ? { pageQuery: window.location.search }
          : {}),
        ...(restoration ? { urlRestore: true } : {}),
        action: invocation.action,
        args: invocation.args,
        ...(invocation.event === undefined ? {} : { event: invocation.event }),
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
      lifecycle?.sending(upload?.kind === 'stage' ? 'xhr' : 'fetch');
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
      if (this.disposed) {
        lifecycle?.result('cancelled');
        return;
      }
      lifecycle?.response(result.status);
      if (this.disposed) {
        lifecycle?.result('cancelled');
        return;
      }
      const streaming = result.headers
        .get('content-type')
        ?.includes('text/event-stream');
      if (
        streaming &&
        (invocation.action === null ||
          invocation.upload ||
          invocation.urlRestore)
      )
        throw requestError('invalid_response', result.status);
      let acceptedStatus = result.status;
      const accepted = await readResponse(
        result,
        this.metadata.id,
        (message) => {
          if (!page || pageEpoch === page.epoch())
            this.bridge.receiveStream(message);
        },
        (status) => {
          acceptedStatus = status;
        },
      );
      if (this.disposed || (streaming && page && pageEpoch !== page.epoch())) {
        lifecycle?.result(this.disposed ? 'cancelled' : 'ignored');
        return;
      }
      lifecycle?.validated(acceptedStatus);
      if ('redirect' in accepted) {
        if (
          invocation.action === null ||
          invocation.upload ||
          invocation.urlRestore
        )
          throw requestError('invalid_response', result.status);
        if (page && pageEpoch !== page.epoch()) {
          lifecycle?.result('ignored');
          return;
        }
        lifecycle?.result('redirect');
        if (accepted.redirect.navigate) {
          const visit = await enhancedRedirect(accepted.redirect, () =>
            this.effects.navigate(accepted.redirect),
          );
          if (
            visit === 'cancelled' ||
            (visit === 'enhanced' && !this.disposed)
          ) {
            lifecycle?.result('ignored');
            return;
          }
        } else this.effects.navigate(accepted.redirect);
        this.leaving = true;
        this.realtime?.dispose();
        this.actionEvents?.dispose();
        this.controls.clearPending();
        this.poller?.dispose();
        this.queue.dispose();
        return;
      }
      if (
        accepted.download &&
        (invocation.action === null ||
          invocation.upload ||
          invocation.urlRestore)
      )
        throw requestError('invalid_response', result.status);
      if (!sameFields(accepted.url ?? {}, this.metadata.url))
        throw requestError('invalid_response', result.status);
      if (
        accepted.html === null &&
        (!this.mounted || invocation.action === null)
      )
        throw requestError('invalid_response', result.status);
      let replacement: HTMLElement | undefined;
      if (accepted.html !== null) {
        try {
          replacement = readRoot(accepted.html, this.metadata.id);
        } catch (cause: unknown) {
          throw requestError('invalid_response', result.status, cause);
        }
      }
      try {
        const mounting = !this.mounted;
        this.apply(accepted, sent, replacement, upload, invocation);
        if (accepted.realtime) this.metadata.realtime = accepted.realtime;
        else delete this.metadata.realtime;
        this.realtime?.update(accepted.realtime, this.metadata.csrf);
        this.realtime?.accepted(accepted.html !== null, realtimeGeneration);
        if (this.parentTicket === parentTicket) this.parentTicket = undefined;
        else if (mounting && this.parentTicket && !this.bridge.paused)
          void this.bridge.sync();
        lifecycle?.applied(accepted.html !== null);
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
    payload: IslandResponse | RenderlessResponse,
    sent: State,
    replacement: HTMLElement | undefined,
    upload?: UploadJob,
    invocation?: Invocation,
  ): void {
    this.controls.captureCustom();
    this.composition?.capture();
    const newer = {
      ...newerWritable(this.bridge.state, sent, payload.state, this.writable),
      ...this.composition?.preserve(payload.state),
    };
    if (this.urlTarget && invocation?.urlRestore?.epoch !== this.url?.epoch())
      Object.assign(newer, this.urlTarget.values);
    const restoreSelection = preserveSelection(this.root);
    const previous = {
      state: this.bridge.state,
      mounted: this.mounted,
      uploads: this.uploads,
      errors: this.bridge.errors,
      snapshot: this.snapshot,
      baseline: this.baseline,
      writable: this.writable,
    };
    try {
      if (replacement) this.controls.beforeMorph();
      if (!this.mounted) {
        this.mounted = true;
        this.writable = payload.writable;
        this.bridge.state = payload.state;
        this.bridge.errors = payload.errors;
        this.uploads = this.createUploads(payload.uploads ?? {});
        this.bridge.uploads = this.uploads.fields;
      }
      if (replacement) this.patch(this.root, replacement);
      this.snapshot = payload.snapshot;
      this.baseline = clone(payload.state);
      this.writable = payload.writable;
      this.bridge.state = mergeChanges(payload.state, newer);
      this.bridge.errors = payload.errors;
      this.uploads.accept(payload.uploads ?? {}, upload);
      this.controls.refresh();
      if (!replacement) {
        this.root.dataset.interactSnapshot = payload.snapshot;
        this.root.dataset.interactState = JSON.stringify(payload.state);
        this.root.dataset.interactWritable = JSON.stringify(payload.writable);
        if (payload.uploads)
          this.root.dataset.interactUploads = JSON.stringify(payload.uploads);
      }
      restoreSelection();
    } catch (cause: unknown) {
      if (!previous.mounted) {
        this.uploads.dispose();
        this.uploads = previous.uploads;
        this.bridge.uploads = this.uploads.fields;
      }
      this.mounted = previous.mounted;
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
    this.metadata.listeners = payload.listeners ?? {};
    this.root.dataset.interactListeners = JSON.stringify(
      this.metadata.listeners,
    );
    if (!previous.mounted) {
      previous.uploads.dispose();
      this.listenForEvents();
    } else this.actionEvents?.update(this.metadata.listeners);
    if (!previous.mounted && this.urlTarget) {
      const target = this.urlTarget;
      this.urlTarget = undefined;
      this.restoreUrl(target);
    }
    this.composition?.accepted(replacement);
    if (payload.download) this.effects.download(payload.download);
    for (const effect of payload.events)
      this.root.dispatchEvent(
        new CustomEvent(effect.name, { detail: effect.detail, bubbles: true }),
      );
  }

  /** Register accepted declarations once the island has an initialized action capability. */
  private listenForEvents(): void {
    this.actionEvents = new ActionEvents(
      this.metadata.listeners,
      (action, event, detail) =>
        this.request(action, [detail], undefined, event),
      (cause) => this.failed(requestError('client_error', null, cause)),
    );
  }

  /**
   * Restore one complete URL selection without capturing or discarding unrelated drafts.
   */
  private restoreUrl(target: UrlRestoration): void {
    if (this.disposed || this.leaving) return;
    if (!this.mounted) {
      this.urlTarget = target;
      return;
    }
    this.controls.captureAll();
    const unchanged = Object.entries(target.values).every(
      ([key, value]) =>
        equal(value, read(this.bridge.state, key)) &&
        equal(value, read(this.baseline, key)),
    );
    if (unchanged && !this.bridge.pending()) return;
    this.controls.clearPending();
    this.urlTarget = target;
    this.bridge.state = mergeChanges(this.bridge.state, target.values);
    this.controls.refresh();
    if (this.bridge.paused && this.bridge.error?.recovery !== 'retry') return;
    this.bridge.error = null;
    this.activity.paused = false;
    void this.enqueueTask({
      action: null,
      args: [],
      urlRestore: target,
      urlEpoch: target.epoch,
    }).catch(() => {
      // The queue owns failure feedback. Keep the selected target available for explicit recovery.
    });
  }

  /** Attach observations only when the queue accepts a distinct task. */
  private enqueueTask(invocation: Invocation): Promise<void> {
    return this.queue.enqueue(invocation, () => {
      const kind: RequestKind = !this.mounted
        ? 'initialize'
        : invocation.upload
          ? invocation.upload.kind === 'stage'
            ? 'upload-stage'
            : 'upload-remove'
          : invocation.urlRestore
            ? 'restore'
            : invocation.action === null
              ? 'sync'
              : 'action';
      return new RequestLifecycle(
        this.root,
        this.metadata.id,
        kind,
        invocation.action,
      );
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
import { defaultTiming } from './configuration';
import type { TimingDefaults } from './configuration';
