import { ownsIslandElement } from '../dom/ownership';
import type { Control } from './control-values';
import { formName, modelNamesChanged } from './form-names';
import { modelBinding } from './model';
import type { ModelBinding } from './model';

type Name = { path: string; list: boolean; type: string; multiple: boolean };
type Registration = {
  modifiers: string[];
  debounce: number;
  active?: Name & { release: () => void };
};

/**
 * Keeps expressionless native models attached to their current HTML name and form.
 *
 * Name resolution shares the form-binding grammar and island ownership boundary.
 * Rebinding releases pending work before restoring the new field's draft; request
 * capture flushes mutations synchronously rather than relying on observer timing.
 */
export class NamedModels {
  private readonly observer: MutationObserver;
  private readonly registrations = new Map<Control, Registration>();
  private readonly lifetime = new AbortController();
  private pending = false;

  constructor(
    private readonly root: HTMLElement,
    private readonly prefix: string,
    private readonly bind: (
      control: Control,
      binding: ModelBinding,
      list: boolean,
    ) => () => void,
    onFailure: (cause: unknown) => void,
  ) {
    const refresh = () => {
      try {
        this.refresh();
      } catch (cause: unknown) {
        onFailure(cause);
      }
    };
    this.observer = new MutationObserver((records) => {
      if (!modelNamesChanged(records, this.prefix)) return;
      this.pending = true;
      refresh();
    });
    this.observer.observe(root, {
      subtree: true,
      childList: true,
      attributes: true,
    });
    // Upload listeners run at the target, before the ordinary bubbled control handler.
    root.addEventListener('change', refresh, {
      capture: true,
      signal: this.lifetime.signal,
    });
  }

  /** Register only an explicitly requested shorthand; ordinary controls remain unbound. */
  register(
    control: Control,
    modifiers: string[],
    debounce: number,
  ): () => void {
    if (this.registrations.has(control))
      throw new Error('Use one x-interact:model binding per control.');
    this.refresh();
    const name = this.resolve(control);
    const entry: Registration = {
      modifiers,
      debounce,
      active: {
        ...name,
        release: this.bind(
          control,
          modelBinding(name.path, modifiers, debounce),
          name.list,
        ),
      },
    };
    this.registrations.set(control, entry);
    return () => {
      entry.active?.release();
      this.registrations.delete(control);
    };
  }

  /** Resolve changed names before input handling, refresh, or an immediate submission. */
  refresh(): void {
    if (modelNamesChanged(this.observer.takeRecords(), this.prefix))
      this.pending = true;
    if (!this.pending) return;
    const candidates: { control: Control; name: Name; entry: Registration }[] =
      [];
    for (const [control, entry] of this.registrations) {
      let name: Name | undefined;
      try {
        if (ownsIslandElement(this.root, control)) name = this.resolve(control);
      } catch (cause: unknown) {
        entry.active?.release();
        delete entry.active;
        throw cause;
      }
      const previous = entry.active;
      if (
        name &&
        previous &&
        name.path === previous.path &&
        name.list === previous.list &&
        name.type === previous.type &&
        name.multiple === previous.multiple
      )
        continue;
      previous?.release();
      delete entry.active;
      if (name) candidates.push({ control, name, entry });
    }
    for (const { control, name, entry } of candidates) {
      const binding = modelBinding(name.path, entry.modifiers, entry.debounce);
      entry.active = {
        ...name,
        release: this.bind(control, binding, name.list),
      };
    }
    this.pending = false;
  }

  /** Cancel name observation and every binding owned by these directives. */
  dispose(): void {
    this.lifetime.abort();
    this.observer.disconnect();
    for (const entry of this.registrations.values()) entry.active?.release();
    this.registrations.clear();
  }

  private resolve(control: Control): Name {
    let base = '';
    const form = control.form;
    if (form && ownsIslandElement(this.root, form)) {
      const models = [...form.attributes].filter(
        (attribute) =>
          attribute.name === `${this.prefix}interact:model` ||
          attribute.name.startsWith(`${this.prefix}interact:model.`),
      );
      if (models.length > 1)
        throw new Error('Use one x-interact:model binding per form.');
      if (models[0]) {
        base = models[0].value;
        if (!base.trim())
          throw new Error('Form models require an explicit state path.');
      }
    }
    const name = formName(base, control.name);
    if (!name)
      throw new Error(
        'x-interact:model without an expression requires a nonempty, non-bookkeeping name.',
      );
    return {
      ...name,
      type: control.type,
      multiple:
        (control instanceof HTMLInputElement ||
          control instanceof HTMLSelectElement) &&
        control.multiple,
    };
  }
}
