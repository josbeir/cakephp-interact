import type { NavigationController } from './controller';
import { linkDestination, updateCurrent } from './links';

const navigationModifiers = [
  'hover',
  'preserve-scroll',
  'trail',
  'no-prefetch',
];

/** Discover opted-in links outside x-data with supported modifiers in any order. */
export function navigationSelector(attribute: string): string {
  const selectors: string[] = [];
  const append = (name: string, remaining: string[]) => {
    const escaped = name.replaceAll(':', '\\:').replaceAll('.', '\\.');
    selectors.push('[' + escaped + ']');
    for (const modifier of remaining)
      append(
        `${name}.${modifier}`,
        remaining.filter((value) => value !== modifier),
      );
  };
  append(attribute, navigationModifiers);
  return selectors.join(',');
}

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
    modifiers.some((modifier) => !navigationModifiers.includes(modifier))
  )
    throw new Error(
      'x-interact:navigate requires a link, no expression, and only hover, preserve-scroll, trail, or no-prefetch modifiers.',
    );
  const current = () => updateCurrent(element, modifiers.includes('trail'));
  current();
  const observer = new MutationObserver(current);
  observer.observe(element, { attributes: true, attributeFilter: ['href'] });
  document.addEventListener('interact:url', current);
  const click = (event: MouseEvent) => {
    const url = linkDestination(element, event);
    if (!url) return;
    cancel();
    event.preventDefault();
    void controller.visit(url.href, {
      preserveScroll: modifiers.includes('preserve-scroll'),
    });
  };
  element.addEventListener('click', click);
  let timer: ReturnType<typeof setTimeout> | undefined;
  const cancel = () => clearTimeout(timer);
  const press = (event: MouseEvent) => {
    const url = linkDestination(element, event);
    if (!url) return;
    cancel();
    controller.prefetch(url.href);
  };
  const prefetch = () => {
    cancel();
    timer = setTimeout(() => {
      const url = linkDestination(element);
      if (url) controller.prefetch(url.href);
    }, 60);
  };
  if (!modifiers.includes('no-prefetch'))
    element.addEventListener('mousedown', press);
  if (modifiers.includes('hover') && !modifiers.includes('no-prefetch')) {
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
    element.removeEventListener('mousedown', press);
    element.removeEventListener('mouseenter', prefetch);
    element.removeEventListener('focus', prefetch);
    element.removeEventListener('mouseleave', cancel);
    element.removeEventListener('blur', cancel);
  };
}
