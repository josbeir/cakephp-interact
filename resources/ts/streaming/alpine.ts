import type { Alpine } from 'alpinejs';
import type { StreamReceiver } from './receiver';

/**
 * Bind a provisional display target using Alpine's DOM initialization and cleanup.
 *
 * Replaced children are destroyed before removal; appended HTML initializes once.
 * The caller attaches the returned cleanup to the owning directive's lifetime.
 */
export function bindStream(
  Alpine: Alpine,
  receiver: StreamReceiver,
  element: HTMLElement,
  expression: string,
  modifiers: string[],
): () => void {
  return receiver.bind(
    element,
    expression,
    modifiers,
    Alpine.prefixed(''),
    (fragment, replace) => {
      Alpine.mutateDom(() => {
        if (replace) {
          const previous = document.createElement('div');
          previous.append(...element.childNodes);
          Alpine.destroyTree(previous);
          element.replaceChildren();
        }
        if (
          fragment.childNodes.length === 1 &&
          fragment.firstChild instanceof Text &&
          element.lastChild instanceof Text
        ) {
          element.lastChild.appendData(fragment.firstChild.data);
          return;
        }
        const children = [...fragment.children];
        element.append(fragment);
        for (const child of children)
          if (child instanceof HTMLElement) Alpine.initTree(child);
      });
    },
  );
}
