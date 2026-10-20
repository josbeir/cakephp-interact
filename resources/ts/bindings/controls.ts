import { FormSubmissions } from './form-submissions';
import { captureControl, refreshControl } from './control-values';
import type { Control } from './control-values';
import type { IslandBridge, Writable } from '../types';

/**
 * Owns native input bindings and synchronization timing for one island root.
 *
 * Edits update the local bridge immediately. Deferred requests, nested ownership,
 * and timer/listener cleanup stay here rather than in the network scheduler.
 */
export class Controls {
  private readonly lifetime = new AbortController();
  private readonly submissions: FormSubmissions;
  private readonly timers = new Map<string, ReturnType<typeof setTimeout>>();

  /**
   * Attach delegated listeners while leaving child islands to their own bindings.
   */
  constructor(
    private readonly root: HTMLElement,
    private readonly bridge: IslandBridge,
    private readonly writable: () => Writable,
  ) {
    this.submissions = new FormSubmissions(root);
    for (const event of ['input', 'change', 'focusout', 'click', 'submit'])
      root.addEventListener(event, (event) => this.handle(event), {
        signal: this.lifetime.signal,
      });
  }

  /**
   * Stop observing the root and cancel synchronization that has not been sent.
   */
  dispose(): void {
    this.lifetime.abort();
    this.clearPending();
    this.submissions.dispose();
  }

  /**
   * Apply reconciled state to surviving native controls after a server render.
   */
  refresh(): void {
    this.submissions.refresh();
    this.controls().forEach((control) =>
      refreshControl(control, this.bridge.state),
    );
  }

  /**
   * Release form mutations before morphing updates application-owned attributes.
   */
  beforeMorph(): void {
    this.submissions.beforeMorph();
  }

  /**
   * Read the latest DOM values before submitting an action or form.
   */
  captureAll(): void {
    this.controls().forEach((control) =>
      captureControl(control, this.bridge.state, this.writable()),
    );
  }

  /**
   * Cancel unsent debounce timers when an explicit request supersedes them.
   */
  clearPending(): void {
    this.timers.forEach((timer) => clearTimeout(timer));
    this.timers.clear();
  }

  /**
   * Capture owned control events and apply their explicit synchronization timing.
   *
   * Submissions and action clicks capture all controls before enqueueing the call.
   * Composition events update drafts without sending intermediate IME input.
   */
  private handle(event: Event): void {
    const target = event.target;
    if (
      !(target instanceof Element) ||
      target.closest('[data-interact-id]') !== this.root
    )
      return;
    if (event.type === 'submit') {
      const form = target.closest<HTMLFormElement>(
        'form[data-interact-submit]',
      );
      if (form) {
        event.preventDefault();
        this.submissions.submit(form, () =>
          this.bridge.call(form.dataset.interactSubmit ?? ''),
        );
      }
      return;
    }
    if (event.type === 'click') {
      const button = target.closest<HTMLElement>('[data-interact-click]');
      if (button && !button.hasAttribute('disabled')) {
        event.preventDefault();
        void this.bridge.call(button.dataset.interactClick ?? '');
      }
      return;
    }
    if (
      !(
        target instanceof HTMLInputElement ||
        target instanceof HTMLSelectElement ||
        target instanceof HTMLTextAreaElement
      ) ||
      !target.dataset.interactModel ||
      target.disabled
    )
      return;
    captureControl(target, this.bridge.state, this.writable());
    if (this.bridge.paused) return;
    const timing = target.dataset.interactUpdate;
    if (
      (timing === 'blur' && event.type === 'focusout') ||
      (timing === 'change' && event.type === 'change')
    )
      void this.bridge.sync();
    if (
      timing === 'input' &&
      event.type === 'input' &&
      !(event instanceof InputEvent && event.isComposing)
    ) {
      const name = target.dataset.interactModel;
      clearTimeout(this.timers.get(name));
      const delay = Number(target.dataset.interactDebounce ?? '250');
      if (!Number.isFinite(delay) || delay < 0 || delay > 60000)
        throw new Error('Invalid debounce delay.');
      this.timers.set(
        name,
        setTimeout(() => {
          this.timers.delete(name);
          void this.bridge.sync();
        }, delay),
      );
    }
  }

  /**
   * Select enabled model controls owned by this root, excluding nested islands.
   */
  private controls(): Control[] {
    return Array.from(
      this.root.querySelectorAll<Control>(
        'input[data-interact-model],select[data-interact-model],textarea[data-interact-model]',
      ),
    ).filter(
      (control) =>
        control.closest('[data-interact-id]') === this.root &&
        !control.disabled,
    );
  }
}
