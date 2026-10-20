import type { ModelBinding } from '../bindings/model';
import { requestError } from '../transport/failure';
import type { RequestResult } from '../types';
import type {
  UploadField,
  UploadItem,
  UploadJob,
  UploadMetadata,
} from './types';

type Selection = { field: string; file: File; controller: AbortController };
type Binding = {
  field: string;
  required: boolean;
  seen: File[];
  change: () => void;
};

/**
 * Owns browser file selections independently of JSON drafts and native input lifetimes.
 *
 * Reactive field handles survive morphs. Raw files and abort controllers stay outside
 * Alpine state, while accepted server references map back to stable local item IDs.
 */
export class UploadFields {
  readonly fields: Record<string, UploadField>;
  revision = 0;
  private accepted: UploadMetadata;
  private readonly selections = new Map<string, Selection>();
  private readonly serverIds = new Map<string, string>();
  private readonly bindings = new Map<HTMLInputElement, Binding>();
  private disposed = false;

  /**
   * Create reactive feedback handles while retaining browser bytes outside Alpine state.
   */
  constructor(
    metadata: UploadMetadata,
    reactive: <T extends object>(value: T) => T,
    private readonly submit: (job: UploadJob) => Promise<RequestResult>,
    private readonly owns: (element: HTMLElement) => boolean,
  ) {
    this.accepted = metadata;
    const fields: Record<string, UploadField> = {};
    for (const [name, declaration] of Object.entries(metadata)) {
      const selection = reactive<{ files: UploadItem[] }>({
        files: declaration.files.map((file) => ({
          ...file,
          status: 'ready',
          progress: 100,
        })),
      });
      fields[name] = reactive<UploadField>({
        get files() {
          return selection.files;
        },
        set files(value: UploadItem[]) {
          selection.files = value;
        },
        get pending() {
          return selection.files.some(
            (file) => file.status === 'queued' || file.status === 'uploading',
          );
        },
        get progress() {
          if (
            !selection.files.length ||
            selection.files.some((file) => file.progress === null)
          )
            return null;
          const total = selection.files.reduce(
            (sum, file) => sum + Math.max(1, file.size),
            0,
          );
          return Math.round(
            selection.files.reduce(
              (sum, file) =>
                sum + Math.max(1, file.size) * (file.progress ?? 0),
              0,
            ) / total,
          );
        },
        cancel: (id) => this.cancel(name, id),
        remove: (id) => this.remove(name, id),
        retry: (id) => this.retry(name, id),
      });
      for (const file of declaration.files)
        this.serverIds.set(file.id, file.id);
    }
    this.fields = reactive(fields);
  }

  /**
   * Bind a native chooser without restoring a filename into its protected value.
   */
  bind(input: HTMLInputElement, binding: ModelBinding): () => void {
    const declaration = this.accepted[binding.path];
    if (
      !declaration ||
      binding.timing !== 'defer' ||
      binding.delay !== 250 ||
      input.multiple !== declaration.multiple
    )
      throw new Error(
        'File inputs require a declared upload, matching multiple, and no timing modifiers.',
      );
    if (this.bindings.has(input))
      throw new Error('Use one upload binding per input.');
    const entry: Binding = {
      field: binding.path,
      required: input.required,
      seen: [],
      change: () => this.capture(input),
    };
    this.bindings.set(input, entry);
    input.addEventListener('change', entry.change);
    this.refresh();
    return () => {
      input.removeEventListener('change', entry.change);
      input.required = entry.required;
      this.bindings.delete(input);
    };
  }

  /**
   * Capture chooser values before actions, including selections without change events.
   */
  captureAll(): void {
    for (const input of this.bindings.keys()) this.capture(input);
  }

  /**
   * Use the latest server-advertised ceiling when a queued selection starts transferring.
   */
  maximum(field: string): number | undefined {
    return this.accepted[field]?.maxSize;
  }

  /**
   * Match ordinary pending feedback and block polling while selections need attention.
   */
  pending(): boolean {
    return Object.values(this.fields).some((field) => field.pending);
  }

  /**
   * Require explicit correction of failed selections before an application action.
   */
  blocked(): boolean {
    return Object.values(this.fields).some((field) =>
      field.files.some((file) => file.status === 'failed'),
    );
  }

  /**
   * Restore native required constraints after accepted selections or PHP clears.
   */
  refresh(): void {
    for (const [input, binding] of this.bindings) {
      if (!this.owns(input)) continue;
      const field = this.fields[binding.field];
      input.required = binding.required && !field?.files.length;
    }
  }

  /**
   * Mark a queued item active, or indicate it was explicitly discarded before execution.
   */
  start(job: UploadJob): boolean {
    if (job.kind === 'remove') return true;
    const file = this.item(job.field, job.localId);
    if (!file || job.signal.aborted) return false;
    file.status = 'uploading';
    return true;
  }

  /**
   * Publish byte progress only to the current selection generation.
   */
  progress(job: UploadJob, value: number | null): void {
    if (job.kind !== 'stage') return;
    const file = this.item(job.field, job.localId);
    if (file) file.progress = value;
  }

  /**
   * Reconcile accepted references without replacing newer queued browser selections.
   */
  accept(metadata: UploadMetadata, job?: UploadJob): void {
    for (const [name, declaration] of Object.entries(metadata)) {
      const field = this.fields[name];
      if (!field)
        throw new Error('The server returned an undeclared upload field.');
      const previous = this.accepted[name]?.files ?? [];
      const added = declaration.files.find(
        (file) => !previous.some((old) => old.id === file.id),
      );
      if (
        job?.kind === 'stage' &&
        job.field === name &&
        added &&
        this.item(name, job.localId)
      )
        this.serverIds.set(job.localId, added.id);
      const ready = declaration.files.map((file): UploadItem => {
        const local =
          [...this.serverIds].find(([, server]) => server === file.id)?.[0] ??
          file.id;
        this.serverIds.set(local, file.id);
        this.selections.delete(local);
        return { ...file, id: local, status: 'ready', progress: 100 };
      });
      if (job?.kind === 'stage' && job.field === name && !added) {
        const rejected = this.item(name, job.localId);
        if (rejected) {
          rejected.status = 'failed';
          this.revision++;
        }
      }
      const local = field.files.filter(
        (file) =>
          file.status !== 'ready' &&
          !ready.some((accepted) => accepted.id === file.id),
      );
      field.files = [...ready, ...local];
    }
    const retained = new Set(
      Object.values(this.fields).flatMap((field) =>
        field.files.map((file) => file.id),
      ),
    );
    for (const id of this.serverIds.keys())
      if (!retained.has(id)) this.serverIds.delete(id);
    this.accepted = metadata;
    this.refresh();
  }

  /**
   * Drop all retained browser bytes and cancel in-flight transfers on island disposal.
   */
  dispose(): void {
    this.disposed = true;
    for (const selection of this.selections.values())
      selection.controller.abort();
    this.selections.clear();
    this.serverIds.clear();
    for (const [input, binding] of this.bindings)
      input.removeEventListener('change', binding.change);
    this.bindings.clear();
    for (const field of Object.values(this.fields)) field.files = [];
  }

  /**
   * Retain newly chosen bytes and queue transfers before clearing the native chooser.
   */
  private capture(input: HTMLInputElement): void {
    const binding = this.bindings.get(input);
    if (!binding || input.disabled || !this.owns(input)) return;
    const files = Array.from(input.files ?? []);
    if (
      files.length === binding.seen.length &&
      files.every((file, index) => file === binding.seen[index])
    )
      return;
    binding.seen = files;
    if (!files.length) return;
    this.revision++;
    if (!this.accepted[binding.field]?.multiple) this.cancel(binding.field);
    const field = this.fields[binding.field];
    if (!field) return;
    for (const file of files) {
      const id = crypto.randomUUID();
      this.selections.set(id, {
        field: binding.field,
        file,
        controller: new AbortController(),
      });
      field.files.push({
        id,
        name: file.name,
        size: file.size,
        status: 'queued',
        progress: 0,
      });
      void this.transfer(id);
    }
    input.value = '';
    binding.seen = [];
    this.refresh();
  }

  /**
   * Find feedback by its stable browser identity across server renders.
   */
  private item(field: string, id: string): UploadItem | undefined {
    return this.fields[field]?.files.find((file) => file.id === id);
  }

  /**
   * Stage one retained selection and publish a failed prerequisite before queued saves.
   */
  private async transfer(id: string): Promise<RequestResult> {
    const selection = this.selections.get(id);
    if (!selection || this.disposed)
      return { ok: false, error: requestError('cancelled', null).failure };
    const result = await this.submit({
      kind: 'stage',
      field: selection.field,
      localId: id,
      file: selection.file,
      signal: selection.controller.signal,
    });
    const item = this.item(selection.field, id);
    if (item && item.status !== 'ready' && item.status !== 'failed') {
      item.status = 'failed';
      this.revision++;
    }
    this.refresh();
    return result;
  }

  /**
   * Abort unfinished selections and invalidate saves that depended on them.
   */
  private cancel(fieldName: string, id?: string): void {
    const field = this.fields[fieldName];
    if (!field) return;
    const cancelled = field.files.filter(
      (file) => file.status !== 'ready' && (id === undefined || file.id === id),
    );
    if (cancelled.length) this.revision++;
    for (const file of cancelled) {
      this.selections.get(file.id)?.controller.abort();
      this.selections.delete(file.id);
    }
    field.files = field.files.filter((file) => !cancelled.includes(file));
    this.refresh();
  }

  /**
   * Remove an accepted server reference or discard an unfinished browser selection.
   */
  private async remove(name: string, id?: string): Promise<RequestResult> {
    const serverId =
      id === undefined
        ? undefined
        : (this.serverIds.get(id) ??
          this.accepted[name]?.files.find((file) => file.id === id)?.id);
    this.cancel(name, id);
    if (id !== undefined && serverId === undefined) return { ok: true };
    this.revision++;
    return this.submit({
      kind: 'remove',
      field: name,
      ...(serverId === undefined ? {} : { id: serverId }),
    });
  }

  /**
   * Repeat only explicitly selected failed transfers using their retained browser bytes.
   */
  private async retry(name: string, id?: string): Promise<RequestResult> {
    const files =
      this.fields[name]?.files.filter(
        (file) =>
          file.status === 'failed' && (id === undefined || file.id === id),
      ) ?? [];
    const results = await Promise.all(
      files.map((file) => {
        const selection = this.selections.get(file.id);
        if (!selection)
          return Promise.resolve<RequestResult>({
            ok: false,
            error: requestError('cancelled', null).failure,
          });
        selection.controller = new AbortController();
        file.status = 'queued';
        file.progress = 0;
        return this.transfer(file.id);
      }),
    );
    return results.find((result) => !result.ok) ?? { ok: true };
  }
}
