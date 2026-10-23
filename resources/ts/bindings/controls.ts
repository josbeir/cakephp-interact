import { ownsIslandElement } from '../dom/ownership';
import { FormSubmissions } from './form-submissions';
import { FormModels } from './form-models';
import { captureControl, captureValue, refreshControl } from './control-values';
import type { Control } from './control-values';
import type { CustomModel } from './custom-model';
import type { ModelBinding } from './model';
import type { IslandBridge, Writable } from '../types';
import { read } from '../state/paths';
import { equal } from '../state/values';

/**
 * Share request timing and ownership across native controls and modelable wrappers.
 */
interface RegisteredModel {
  binding: ModelBinding;
  capture(enabled?: boolean): void;
  refresh(): void;
  custom: boolean;
}

/**
 * Coordinates Alpine-registered native controls and custom values for one island.
 *
 * Bindings update local drafts immediately while explicit timing governs server
 * synchronization. The same registry flushes current DOM values before actions,
 * preserving submit-before-blur edits and independently owned child islands.
 */
export class Controls {
  private readonly lifetime = new AbortController();
  private readonly models = new Map<HTMLElement, RegisteredModel>();
  private readonly composing = new WeakSet<HTMLElement>();
  private readonly submissions: FormSubmissions;
  private readonly timers = new Map<
    string,
    { source: HTMLElement; timer: ReturnType<typeof setTimeout> }
  >();
  private readonly forms = new Map<HTMLFormElement, FormModels>();

  /**
   * Attach owned draft events and route expression failures into island feedback.
   */
  constructor(
    private readonly root: HTMLElement,
    private readonly bridge: IslandBridge,
    private readonly writable: () => Writable,
    private readonly onFailure: (cause: unknown) => void,
  ) {
    this.submissions = new FormSubmissions(root, onFailure);
    for (const event of [
      'input',
      'change',
      'focusout',
      'compositionstart',
      'compositionend',
    ])
      root.addEventListener(event, (event) => this.handle(event), {
        signal: this.lifetime.signal,
      });
  }

  /**
   * Register a native model for its Alpine directive's lifetime.
   */
  bindModel(
    control: Control,
    binding: ModelBinding,
    list?: boolean,
  ): () => void {
    if (list === undefined) this.refreshForms();
    return this.register(control, {
      binding,
      custom: false,
      capture: () =>
        captureControl(
          control,
          binding.path,
          this.bridge.state,
          this.writable(),
          list ?? false,
        ),
      refresh: () => refreshControl(control, binding.path, this.bridge.state),
    });
  }

  /**
   * Infer native control paths relative to explicitly writable array state.
   */
  bindForm(
    form: HTMLFormElement,
    binding: ModelBinding,
    prefix: string,
  ): () => void {
    const first = binding.path.split('.')[0] ?? '';
    const value = read(this.bridge.state, binding.path);
    if (
      this.writable()[first]?.type !== 'array' ||
      (value !== undefined && (value === null || typeof value !== 'object'))
    )
      throw new Error('Form models require writable array state.');
    if (this.forms.has(form))
      throw new Error('Use one x-interact:model binding per form.');
    this.refreshForms();
    const models = new FormModels(
      this.root,
      form,
      binding,
      prefix,
      (control, inferred, list) => this.bindModel(control, inferred, list),
      () => {
        try {
          this.refreshForms();
        } catch (cause: unknown) {
          this.onFailure(cause);
        }
      },
    );
    this.forms.set(form, models);
    return () => {
      models.dispose();
      this.forms.delete(form);
    };
  }

  /**
   * Bind a widget's exposed value while retaining normal draft and timing behavior.
   */
  bindCustomModel(
    element: HTMLElement,
    binding: ModelBinding,
    source: CustomModel,
  ): () => void {
    return this.register(element, {
      binding,
      custom: true,
      capture: (enabled = true) => {
        // Keep reactive dependencies even while a wrapper is disabled.
        const value = source.read();
        if (!enabled) return;
        if (value === undefined) {
          if (read(this.bridge.state, binding.path) === undefined) return;
          throw new Error('Custom models require JSON values.');
        }
        if (equal(value, read(this.bridge.state, binding.path))) return;
        captureValue(value, binding.path, this.bridge.state, this.writable());
      },
      refresh: () => {
        const value = read(this.bridge.state, binding.path);
        if (!equal(source.read(), value)) source.write(value);
      },
    });
  }

  /**
   * Capture reactive widget edits without echoing accepted server values as requests.
   */
  customChanged(element: HTMLElement): void {
    const model = this.models.get(element);
    if (!model || !this.owns(element)) return;
    try {
      const before = read(this.bridge.state, model.binding.path);
      model.capture(!this.disabled(element));
      if (!equal(before, read(this.bridge.state, model.binding.path)))
        this.schedule(
          element,
          model.binding,
          model.binding.timing === 'change' ? 'change' : 'input',
        );
    } catch (cause: unknown) {
      this.onFailure(cause);
    }
  }

  /**
   * Register one source and release its unsent synchronization when removed.
   */
  private register(control: HTMLElement, model: RegisteredModel): () => void {
    if (this.models.has(control))
      throw new Error('Use one x-interact:model binding per control.');
    model.refresh();
    this.models.set(control, model);
    return () => {
      if (this.models.get(control) !== model) return;
      this.models.delete(control);
      const pending = this.timers.get(model.binding.path);
      if (pending?.source === control) {
        clearTimeout(pending.timer);
        this.timers.delete(model.binding.path);
      }
    };
  }

  /**
   * Protect a form's expression while retaining native validation and Alpine scope.
   */
  bindSubmit(
    form: HTMLFormElement,
    attribute: string,
    expression: string,
    evaluate: (event: Event) => unknown,
  ): () => void {
    const listener = (event: Event) => {
      if (event.target !== form || !this.owns(form)) return;
      event.preventDefault();
      this.submissions.submit(form, attribute, expression, () =>
        evaluate(event),
      );
    };
    form.addEventListener('submit', listener, { signal: this.lifetime.signal });
    return () => form.removeEventListener('submit', listener);
  }

  /**
   * Stop observing the root and cancel synchronization that has not been sent.
   */
  dispose(): void {
    this.lifetime.abort();
    for (const form of this.forms.values()) form.dispose();
    this.forms.clear();
    this.clearPending();
    this.models.clear();
    this.submissions.dispose();
  }

  /**
   * Apply reconciled state to surviving registered controls after a server render.
   */
  refresh(): void {
    this.refreshForms();
    this.submissions.refresh();
    for (const [, model] of this.controls()) model.refresh();
  }

  /**
   * Release form mutations before a server render establishes new DOM state.
   */
  beforeMorph(): void {
    this.submissions.beforeMorph();
  }

  /**
   * Capture native and custom values even before their edit effects have run.
   */
  captureAll(): void {
    this.refreshForms();
    for (const [, model] of this.controls()) model.capture();
  }

  /**
   * Flush custom values before reconciliation without replacing native event-captured drafts.
   */
  captureCustom(): void {
    for (const [, model] of this.controls()) {
      if (model.custom) model.capture();
    }
  }

  /**
   * Cancel unsent debounce timers when an explicit request supersedes them.
   */
  clearPending(): void {
    this.timers.forEach(({ timer }) => clearTimeout(timer));
    this.timers.clear();
  }

  /**
   * Keep background polling from flushing a scheduled model synchronization.
   */
  hasPending(): boolean {
    return this.timers.size > 0;
  }

  /**
   * Update registered drafts and schedule only their declared synchronization mode.
   */
  private handle(event: Event): void {
    try {
      this.refreshForms();
    } catch (cause: unknown) {
      this.onFailure(cause);
      return;
    }
    const control = event.target;
    if (
      event.type === 'focusout' &&
      control instanceof HTMLElement &&
      this.owns(control)
    ) {
      for (const [element, model] of this.controls()) {
        if (
          model.custom &&
          element.contains(control) &&
          !(
            event instanceof FocusEvent &&
            event.relatedTarget instanceof Node &&
            element.contains(event.relatedTarget)
          )
        ) {
          try {
            model.capture();
            this.schedule(element, model.binding, 'focusout');
          } catch (cause: unknown) {
            this.onFailure(cause);
          }
        }
      }
    }
    if (
      !(
        control instanceof HTMLInputElement ||
        control instanceof HTMLSelectElement ||
        control instanceof HTMLTextAreaElement
      ) ||
      !this.owns(control) ||
      this.disabled(control)
    )
      return;
    const model = this.models.get(control);
    if (!model || model.custom) return;
    if (event.type === 'compositionstart') this.composing.add(control);
    if (event.type === 'compositionend') this.composing.delete(control);
    try {
      model.capture();
    } catch (cause: unknown) {
      this.onFailure(cause);
      return;
    }
    this.schedule(
      control,
      model.binding,
      event.type === 'compositionend' ? 'input' : event.type,
      this.composing.has(control) ||
        (event instanceof InputEvent && event.isComposing),
    );
  }

  /**
   * Schedule edits and recheck their source before delayed synchronization.
   */
  private schedule(
    element: HTMLElement,
    binding: ModelBinding,
    event: string,
    composing = false,
  ): void {
    if (this.bridge.paused) return;
    if (
      (binding.timing === 'blur' && event === 'focusout') ||
      (binding.timing === 'change' && event === 'change')
    )
      void this.bridge.sync();
    if (
      binding.timing === 'live' &&
      (event === 'input' || event === 'compositionstart')
    ) {
      clearTimeout(this.timers.get(binding.path)?.timer);
      this.timers.delete(binding.path);
      if (composing) return;
      this.timers.set(binding.path, {
        source: element,
        timer: setTimeout(() => {
          this.timers.delete(binding.path);
          if (!this.owns(element) || this.disabled(element)) return;
          void this.bridge.sync();
        }, binding.delay),
      });
    }
  }

  /**
   * Select enabled registered models without crossing nested island ownership.
   */
  private controls(): [HTMLElement, RegisteredModel][] {
    return [...this.models].filter(
      ([control]) => this.owns(control) && !this.disabled(control),
    );
  }

  /**
   * Resolve ownership from the current DOM, including controls moved between islands.
   */
  private owns(element: HTMLElement): boolean {
    return ownsIslandElement(this.root, element);
  }

  /**
   * Honor native disabled controls and explicitly disabled custom model wrappers.
   */
  private disabled(element: HTMLElement): boolean {
    return element.hasAttribute('disabled') || element.matches(':disabled');
  }

  /**
   * Flush discovery synchronously so immediate actions see newly associated controls.
   */
  private refreshForms(): void {
    // Release every previous owner before a control can join another bound form.
    for (const form of this.forms.values()) form.refresh(true);
    for (const form of this.forms.values()) form.refresh();
  }
}
