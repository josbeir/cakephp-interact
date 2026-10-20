import type { Alpine } from 'alpinejs';
import type { Patcher } from '../types';

/**
 * Create a keyed morph operation using the host application's Alpine instance.
 *
 * Signed child placeholders preserve the existing child root and its local state.
 * All other markup remains subject to Alpine Morph's normal lifecycle behavior.
 */
export function morphRoot(Alpine: Alpine): Patcher {
  return (root, replacement) => {
    Alpine.morph(root, replacement, {
      key: (node) =>
        node instanceof Element
          ? (node.getAttribute('data-interact-id') ??
            node.getAttribute('data-interact-preserve') ??
            node.id)
          : '',
      updating: (node, to, _childrenOnly, skip) => {
        if (
          node instanceof HTMLElement &&
          to instanceof HTMLElement &&
          node !== root &&
          node.hasAttribute('data-interact-id') &&
          to.getAttribute('data-interact-preserve') ===
            node.getAttribute('data-interact-id')
        )
          skip();
        else if (
          node instanceof Element &&
          node !== root &&
          node.hasAttribute(Alpine.prefixed('interact:ignore'))
        )
          skip();
      },
    });
  };
}
