import { formName } from './form-names';
import type { Control } from './control-values';
import type { ModelBinding } from './model';

type Registration = { path: string; list: boolean; release: () => void };
type Candidate = { path: string; list: boolean };

/**
 * Discovers native controls owned by one form and registers inferred models.
 *
 * Native form association includes external controls. Mutations and synchronous
 * capture reconcile ownership before using the registry; values still flow
 * through the same draft, timing, and reconciliation code as explicit models.
 */
export class FormModels {
  private readonly observer: MutationObserver;
  private readonly registrations = new Map<Control, Registration>();
  private candidates: Map<Control, Candidate> | undefined;
  private pending = true;

  constructor(
    private readonly root: HTMLElement,
    private readonly form: HTMLFormElement,
    private readonly binding: ModelBinding,
    private readonly prefix: string,
    private readonly bind: (
      control: Control,
      binding: ModelBinding,
      list: boolean,
    ) => () => void,
    onChange: () => void,
  ) {
    this.observer = new MutationObserver((records) => {
      if (this.changed(records)) {
        this.candidates = undefined;
        onChange();
      }
    });
    try {
      this.refresh();
    } catch (cause: unknown) {
      this.dispose();
      throw cause;
    }
    this.observer.observe(root, {
      subtree: true,
      childList: true,
      attributes: true,
    });
  }

  /**
   * Reconcile names, form associations, explicit overrides, and island boundaries.
   */
  refresh(releaseOnly = false): void {
    if (this.changed(this.observer.takeRecords())) this.candidates = undefined;
    if (this.candidates === undefined) {
      this.candidates = this.discover();
      this.pending = true;
    }
    if (!this.pending) return;
    for (const [control, registration] of this.registrations) {
      const candidate = this.candidates.get(control);
      if (
        candidate?.path === registration.path &&
        candidate.list === registration.list
      )
        continue;
      registration.release();
      this.registrations.delete(control);
    }
    if (releaseOnly) return;
    for (const [control, name] of this.candidates) {
      if (this.registrations.has(control)) continue;
      this.registrations.set(control, {
        ...name,
        release: this.bind(
          control,
          { ...this.binding, path: name.path },
          name.list,
        ),
      });
    }
    this.pending = false;
  }

  /** Cache ownership discovery across both refresh passes until relevant DOM mutations arrive. */
  private discover(): Map<Control, Candidate> {
    const candidates = new Map<Control, Candidate>();
    const grouped = new Set<string>();
    if (this.owns(this.form)) {
      for (const element of this.form.elements) {
        if (!isControl(element) || !this.eligible(element)) continue;
        // Match native fallbacks even when their visible peer has an absolute override.
        if (
          (element instanceof HTMLInputElement &&
            ['checkbox', 'radio'].includes(element.type)) ||
          (element instanceof HTMLSelectElement && element.multiple)
        )
          grouped.add(
            element.name
              .replace(/\[\]$/, '')
              .replace(/\[([a-zA-Z0-9_-]+)\]/g, '.$1'),
          );
        if (this.hasDirective(element, 'interact:model')) continue;
        const name = formName(this.binding.path, element.name);
        if (name) candidates.set(element, name);
      }
    }
    // CakePHP adds hidden fallbacks before checkboxes, radios, and multiple selects.
    // The visible control owns the value, including an empty checked list value.
    for (const [control, name] of candidates) {
      if (
        control instanceof HTMLInputElement &&
        control.type === 'hidden' &&
        grouped.has(name.path.slice(this.binding.path.length + 1))
      )
        candidates.delete(control);
    }
    return candidates;
  }

  /**
   * Release every inferred model and its pending work with the form directive.
   */
  dispose(): void {
    this.observer.disconnect();
    for (const registration of this.registrations.values())
      registration.release();
    this.registrations.clear();
    this.candidates = undefined;
  }

  /**
   * Observe ownership changes without responding to feedback text or presentation.
   */
  private changed(records: MutationRecord[]): boolean {
    return records.some((record) => {
      if (record.type === 'attributes') {
        const name = record.attributeName ?? '';
        return (
          [
            'name',
            'form',
            'id',
            'type',
            'multiple',
            'data-interact-id',
          ].includes(name) ||
          ['model', 'modelable', 'interact:model', 'interact:ignore'].some(
            (directive) =>
              name === `${this.prefix}${directive}` ||
              name.startsWith(`${this.prefix}${directive}.`),
          )
        );
      }
      return [...record.addedNodes, ...record.removedNodes].some(
        (node) =>
          node instanceof Element &&
          (node.matches('input,select,textarea,form') ||
            node.querySelector('input,select,textarea,form') !== null),
      );
    });
  }

  private owns(element: HTMLElement): boolean {
    return (
      this.root.contains(element) &&
      element.closest('[data-interact-id]') === this.root
    );
  }

  private hasDirective(element: HTMLElement, directive: string): boolean {
    return element
      .getAttributeNames()
      .some(
        (name) =>
          name === `${this.prefix}${directive}` ||
          name.startsWith(`${this.prefix}${directive}.`),
      );
  }

  private eligible(control: Control): boolean {
    if (!this.owns(control) || this.hasDirective(control, 'model'))
      return false;
    if (
      control instanceof HTMLInputElement &&
      ['submit', 'reset', 'button', 'image', 'file'].includes(control.type)
    )
      return false;
    for (
      let ancestor: HTMLElement | null = control;
      ancestor && ancestor !== this.root;
      ancestor = ancestor.parentElement
    ) {
      if (
        this.hasDirective(ancestor, 'interact:ignore') ||
        this.hasDirective(ancestor, 'modelable')
      )
        return false;
    }
    return true;
  }
}

/**
 * Narrow native form elements without including fieldsets, buttons, or objects.
 */
function isControl(element: Element): element is Control {
  return (
    element instanceof HTMLInputElement ||
    element instanceof HTMLSelectElement ||
    element instanceof HTMLTextAreaElement
  );
}
