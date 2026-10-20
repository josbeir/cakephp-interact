import { FormSubmissions } from './form-submissions';
import { captureControl, refreshControl } from './control-values';
import type { Control } from './control-values';
import type { ModelBinding } from './model';
import type { IslandBridge, Writable } from '../types';

/**
 * Coordinates Alpine-registered native controls for one island.
 *
 * Bindings update local drafts immediately while explicit timing governs server
 * synchronization. The same registry flushes current DOM values before actions,
 * preserving submit-before-blur edits and independently owned child islands.
 */
export class Controls {
  private readonly lifetime = new AbortController();
  private readonly models = new Map<Control, ModelBinding>();
  private readonly submissions: FormSubmissions;
  private readonly timers = new Map<string, ReturnType<typeof setTimeout>>();

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
    for (const event of ['input', 'change', 'focusout'])
      root.addEventListener(event, (event) => this.handle(event), {
        signal: this.lifetime.signal,
      });
  }

  /**
   * Register a native model for its Alpine directive's lifetime.
   */
  bindModel(control: Control, binding: ModelBinding): () => void {
    if (this.models.has(control))
      throw new Error('Use one x-interact:model binding per control.');
    this.models.set(control, binding);
    refreshControl(control, binding.path, this.bridge.state);
    return () => {
      if (this.models.get(control) !== binding) return;
      this.models.delete(control);
      clearTimeout(this.timers.get(binding.path));
      this.timers.delete(binding.path);
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
    this.clearPending();
    this.models.clear();
    this.submissions.dispose();
  }

  /**
   * Apply reconciled state to surviving registered controls after a server render.
   */
  refresh(): void {
    this.submissions.refresh();
    for (const [control, binding] of this.controls())
      refreshControl(control, binding.path, this.bridge.state);
  }

  /**
   * Release form mutations before a server render establishes new DOM state.
   */
  beforeMorph(): void {
    this.submissions.beforeMorph();
  }

  /**
   * Capture native DOM values even when a control has not emitted its edit event.
   */
  captureAll(): void {
    for (const [control, binding] of this.controls())
      captureControl(control, binding.path, this.bridge.state, this.writable());
  }

  /**
   * Cancel unsent debounce timers when an explicit request supersedes them.
   */
  clearPending(): void {
    this.timers.forEach((timer) => clearTimeout(timer));
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
    const control = event.target;
    if (
      !(
        control instanceof HTMLInputElement ||
        control instanceof HTMLSelectElement ||
        control instanceof HTMLTextAreaElement
      ) ||
      !this.owns(control) ||
      control.disabled
    )
      return;
    const binding = this.models.get(control);
    if (!binding) return;
    try {
      captureControl(control, binding.path, this.bridge.state, this.writable());
    } catch (cause: unknown) {
      this.onFailure(cause);
      return;
    }
    if (this.bridge.paused) return;
    if (
      (binding.timing === 'blur' && event.type === 'focusout') ||
      (binding.timing === 'change' && event.type === 'change')
    )
      void this.bridge.sync();
    if (
      binding.timing === 'live' &&
      event.type === 'input' &&
      !(event instanceof InputEvent && event.isComposing)
    ) {
      clearTimeout(this.timers.get(binding.path));
      this.timers.set(
        binding.path,
        setTimeout(() => {
          this.timers.delete(binding.path);
          void this.bridge.sync();
        }, binding.delay),
      );
    }
  }

  /**
   * Select enabled registered models without crossing nested island ownership.
   */
  private controls(): [Control, ModelBinding][] {
    return [...this.models].filter(
      ([control]) => this.owns(control) && !control.disabled,
    );
  }

  /**
   * Resolve ownership from the current DOM, including controls moved between islands.
   */
  private owns(element: HTMLElement): boolean {
    return (
      this.root.contains(element) &&
      element.closest('[data-interact-id]') === this.root
    );
  }
}
