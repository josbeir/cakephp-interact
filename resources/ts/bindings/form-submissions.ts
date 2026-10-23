import { ownsIslandElement } from '../dom/ownership';
/**
 * A native submit control whose disabled state can be temporarily owned by a lock.
 */
type SubmitControl = HTMLButtonElement | HTMLInputElement;

type Submission = {
  form: HTMLFormElement;
  key: string | null;
  attribute: string;
  expression: string;
  buttons: Map<SubmitControl, boolean>;
  busy: string | null;
  protected: boolean;
};

/**
 * Protects declarative native forms while their accepted submissions settle.
 *
 * Locks belong to one island and follow surviving forms or stable native IDs.
 * Temporary DOM changes are released around morphs so server-authored disabled
 * states remain authoritative; no particular PHP form helper is involved.
 */
export class FormSubmissions {
  private readonly observer: MutationObserver;
  private readonly submissions = new Set<Submission>();

  /**
   * Restrict form and submitter ownership to the current island root.
   */
  constructor(
    private readonly root: HTMLElement,
    private readonly onFailure: (cause: unknown) => void,
  ) {
    this.observer = new MutationObserver((records) => {
      this.readChanges(records);
      this.refresh();
    });
  }

  /**
   * Accept a form once, capture through the bridge, then protect its submitters.
   */
  submit(
    form: HTMLFormElement,
    attribute: string,
    expression: string,
    invoke: () => unknown,
  ): void {
    if ([...this.submissions].some((entry) => entry.form === form)) return;
    const entry: Submission = {
      form,
      key: form.id || null,
      attribute,
      expression,
      buttons: new Map(),
      busy: form.getAttribute('aria-busy'),
      protected: false,
    };
    this.submissions.add(entry);
    const release = () => {
      this.readChanges(this.observer.takeRecords());
      this.observer.disconnect();
      this.restore(entry);
      this.submissions.delete(entry);
      this.refresh();
    };
    try {
      const result = invoke();
      this.refresh();
      void Promise.resolve(result).then(release, (cause: unknown) => {
        release();
        this.onFailure(cause);
      });
    } catch (cause: unknown) {
      release();
      this.onFailure(cause);
    }
  }

  /**
   * Release temporary mutations before a server render establishes new DOM state.
   */
  beforeMorph(): void {
    this.readChanges(this.observer.takeRecords());
    this.observer.disconnect();
    for (const entry of this.submissions) this.restore(entry);
  }

  /**
   * Reapply outstanding locks to owned forms and their current submit buttons.
   */
  refresh(): void {
    this.readChanges(this.observer.takeRecords());
    this.observer.disconnect();
    if (this.submissions.size === 0) return;
    const forms = Array.from(
      this.root.querySelectorAll<HTMLFormElement>('form'),
    );
    for (const entry of this.submissions) {
      if (!this.owns(entry.form) && entry.key !== null) {
        const replacement = forms.find(
          (form) =>
            this.owns(form) &&
            form.id === entry.key &&
            form.getAttribute(entry.attribute) === entry.expression,
        );
        if (replacement) entry.form = replacement;
      }
      if (!this.owns(entry.form)) continue;
      if (!entry.protected) entry.busy = entry.form.getAttribute('aria-busy');
      entry.protected = true;
      entry.form.setAttribute('aria-busy', 'true');
      for (const button of this.root.querySelectorAll('button,input')) {
        if (
          !(
            button instanceof HTMLButtonElement ||
            button instanceof HTMLInputElement
          ) ||
          button.form !== entry.form ||
          !this.owns(button) ||
          !(
            button.type === 'submit' ||
            (button instanceof HTMLInputElement && button.type === 'image')
          )
        )
          continue;
        if (!entry.buttons.has(button))
          entry.buttons.set(button, button.disabled);
        button.disabled = true;
      }
    }
    if (this.submissions.size)
      this.observer.observe(this.root, {
        subtree: true,
        childList: true,
        attributes: true,
        attributeFilter: ['disabled'],
      });
  }

  /**
   * Restore surviving DOM state when the owning island is disposed.
   */
  dispose(): void {
    this.beforeMorph();
    this.submissions.clear();
  }

  /**
   * Remember application disabled changes separately from our temporary lock.
   */
  private readChanges(records: MutationRecord[]): void {
    for (const record of records) {
      const button = record.target;
      if (
        !(
          button instanceof HTMLButtonElement ||
          button instanceof HTMLInputElement
        ) ||
        record.type !== 'attributes'
      )
        continue;
      for (const entry of this.submissions) {
        if (entry.buttons.has(button))
          entry.buttons.set(button, button.disabled);
      }
    }
  }

  /**
   * Check native DOM ownership without crossing independently managed child islands.
   */
  private owns(element: HTMLElement): boolean {
    return ownsIslandElement(this.root, element);
  }

  /**
   * Restore only mutations still owned by this submission.
   */
  private restore(entry: Submission): void {
    if (!entry.protected) return;
    entry.protected = false;
    for (const [button, disabled] of entry.buttons) {
      if (this.owns(button) && button.disabled) button.disabled = disabled;
    }
    entry.buttons.clear();
    if (this.owns(entry.form)) {
      if (entry.busy === null) entry.form.removeAttribute('aria-busy');
      else entry.form.setAttribute('aria-busy', entry.busy);
    }
  }
}
