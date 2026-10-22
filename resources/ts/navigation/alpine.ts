import type { NavigationController } from './controller';
import { linkDestination, updateCurrent } from './links';

/** Register page directives through Interact's existing Alpine directive dispatcher. */
export function navigationBinding(
  controller: NavigationController,
  element: HTMLElement,
  value: string,
  modifiers: string[],
  expression: string,
): () => void {
  if (value === 'persist') {
    if (modifiers.length || !expression.trim())
      throw new Error(
        'x-interact:persist requires a literal nonempty key without modifiers.',
      );
    return () => {};
  }
  if (
    !(element instanceof HTMLAnchorElement) ||
    expression.trim() ||
    modifiers.some(
      (modifier) => !['hover', 'preserve-scroll', 'trail'].includes(modifier),
    )
  )
    throw new Error(
      'x-interact:navigate requires a link, no expression, and only hover, preserve-scroll, or trail modifiers.',
    );
  const current = () => updateCurrent(element, modifiers.includes('trail'));
  current();
  const observer = new MutationObserver(current);
  observer.observe(element, { attributes: true, attributeFilter: ['href'] });
  document.addEventListener('interact:url', current);
  const click = (event: MouseEvent) => {
    const url = linkDestination(element, event);
    if (!url) return;
    event.preventDefault();
    void controller.visit(url.href, {
      preserveScroll: modifiers.includes('preserve-scroll'),
    });
  };
  element.addEventListener('click', click);
  let timer: ReturnType<typeof setTimeout> | undefined;
  const cancel = () => clearTimeout(timer);
  const prefetch = () => {
    cancel();
    timer = setTimeout(() => {
      const url = linkDestination(element);
      if (url) controller.prefetch(url.href);
    }, 60);
  };
  if (modifiers.includes('hover')) {
    element.addEventListener('mouseenter', prefetch);
    element.addEventListener('focus', prefetch);
    element.addEventListener('mouseleave', cancel);
    element.addEventListener('blur', cancel);
  }
  return () => {
    cancel();
    observer.disconnect();
    document.removeEventListener('interact:url', current);
    element.removeEventListener('click', click);
    element.removeEventListener('mouseenter', prefetch);
    element.removeEventListener('focus', prefetch);
    element.removeEventListener('mouseleave', cancel);
    element.removeEventListener('blur', cancel);
  };
}
