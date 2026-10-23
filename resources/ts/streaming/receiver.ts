import { identifier, streamMessage } from './messages';

interface Target {
  element: HTMLElement;
  observer: MutationObserver;
  dirty: boolean;
  name: string;
  html: boolean;
  prefix: string;
  update: (fragment: DocumentFragment, replace: boolean) => void;
}

/**
 * Owns display targets for one island and validates adapter data before touching the DOM.
 *
 * Transport adapters may deliver only provisional display messages. Signed state and
 * terminal request results remain exclusive to the normal request lifecycle.
 */
export class StreamReceiver {
  private readonly targets = new Map<HTMLElement, Target>();

  /** Bind message delivery to this island and its disposal state. */
  constructor(
    private readonly root: HTMLElement,
    private readonly active: () => boolean,
  ) {}

  /** Register a display-only target with the host Alpine instance's lifecycle operation. */
  bind(
    element: HTMLElement,
    name: string,
    modifiers: string[],
    prefix: string,
    update: Target['update'],
  ): () => void {
    if (
      !identifier(name) ||
      modifiers.some((modifier) => modifier !== 'html') ||
      modifiers.length > 1 ||
      element === this.root ||
      this.targets.has(element)
    )
      throw new Error(
        'x-interact:stream requires a literal name and optional .html modifier on an island descendant.',
      );
    this.assertAncestors(element, prefix);
    this.assertDisplay(element, prefix, element);
    const observer = new MutationObserver((records) => {
      const target = this.targets.get(element);
      if (target && records.length) target.dirty = true;
    });
    observer.observe(element, {
      childList: true,
      attributes: true,
      subtree: true,
    });
    const target: Target = {
      element,
      observer,
      dirty: false,
      name,
      html: modifiers.includes('html'),
      prefix,
      update,
    };
    this.targets.set(element, target);
    return () => {
      observer.disconnect();
      this.targets.delete(element);
    };
  }

  /** Accept a normalized adapter message for this island, ignoring removed targets. */
  receive(value: unknown): void {
    if (!this.active()) return;
    if (!streamMessage(value) || value.id !== this.root.dataset.interactId)
      throw new Error('Invalid stream message.');
    if (value.type === 'event') {
      this.root.dispatchEvent(
        new CustomEvent('interact:stream', {
          detail: { name: value.name, detail: value.detail },
          bubbles: true,
        }),
      );
      return;
    }
    const updates: { target: Target; fragment: DocumentFragment }[] = [];
    for (const [element, target] of this.targets) {
      if (
        target.name !== value.to ||
        !this.root.contains(element) ||
        element.closest('[data-interact-id]') !== this.root
      )
        continue;
      this.assertAncestors(element, target.prefix);
      const mutated = target.observer.takeRecords().length > 0;
      if (target.dirty || mutated) {
        this.assertDisplay(element, target.prefix, element);
        target.dirty = false;
      }
      if (target.html !== value.html)
        throw new Error(
          'Stream content must match the target text or .html mode.',
        );
      const fragment = document.createDocumentFragment();
      if (value.html) {
        const template = document.createElement('template');
        template.innerHTML = value.content;
        this.assertDisplay(template.content, target.prefix);
        fragment.append(template.content);
      } else fragment.append(document.createTextNode(value.content));
      updates.push({ target, fragment });
    }
    for (const { target, fragment } of updates) {
      const added = new Set<Node>(fragment.childNodes);
      target.update(fragment, value.replace);
      // Managed insertion is already validated. Alpine or application mutations inside
      // that content invalidate the cache and are rechecked before its next update.
      target.dirty ||= target.observer
        .takeRecords()
        .some(
          (record) =>
            record.type !== 'childList' ||
            record.target !== target.element ||
            [...record.addedNodes].some((node) => !added.has(node)),
        );
    }
  }

  /** Release target closures on island disposal, including adapters retaining an old bridge. */
  dispose(): void {
    for (const target of this.targets.values()) target.observer.disconnect();
    this.targets.clear();
  }

  /** Check surrounding ownership even when the verified target content is unchanged. */
  private assertAncestors(element: HTMLElement, prefix: string): void {
    for (
      let ancestor: HTMLElement | null = element;
      ancestor && ancestor !== this.root;
      ancestor = ancestor.parentElement
    ) {
      if (
        [...ancestor.attributes].some(
          ({ name }) =>
            name === `${prefix}interact:ignore` ||
            name.startsWith(`${prefix}modelable`),
        )
      )
        throw new Error('Stream targets cannot overwrite widget-owned DOM.');
    }
  }

  /** Reject executable markup, controls, and runtime regions before insertion or replacement. */
  private assertDisplay(
    scope: ParentNode,
    prefix: string,
    target?: HTMLElement,
  ): void {
    const elements = [...scope.querySelectorAll('*')];
    if (scope instanceof Element) elements.unshift(scope);
    for (const element of elements) {
      if (
        element.matches(
          'script, style, link, iframe, object, embed, template, form, input, select, textarea, button, [contenteditable]',
        )
      )
        throw new Error(
          'Stream targets and fragments must contain display-only markup.',
        );
      for (const { name } of element.attributes) {
        if (
          name.startsWith('data-interact-') ||
          name.startsWith(`${prefix}model`) ||
          name === `${prefix}ignore` ||
          name.startsWith('on') ||
          (name.startsWith(`${prefix}interact:`) &&
            !(
              element === target &&
              (name === `${prefix}interact:stream` ||
                name === `${prefix}interact:stream.html`)
            ))
        )
          throw new Error(
            'Stream content cannot introduce controls or runtime-owned regions.',
          );
      }
    }
  }
}
