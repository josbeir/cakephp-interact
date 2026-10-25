import type { NavigationController } from './controller';
import { linkDestination, updateCurrent } from './links';
import { assetPolicy } from './head';

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
  if (value === 'asset') {
    assetPolicy(element, controller.assetAttribute());
    if (
      modifiers.length ||
      (expression !== 'reload' && expression !== 'persist')
    )
      throw new Error('Invalid head asset policy.');
    return () => {};
  }
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
  const observer = new MutationObserver(() => {
    release();
    current();
  });
  observer.observe(element, { attributes: true, attributeFilter: ['href'] });
  document.addEventListener('interact:url', current);
  let pressed: { url: string; controller: AbortController } | undefined;
  let releaseTimer: ReturnType<typeof setTimeout> | undefined;
  const release = () => {
    clearTimeout(releaseTimer);
    pressed?.controller.abort();
    pressed = undefined;
    document.removeEventListener('mouseup', released, true);
    document.removeEventListener('mousedown', release, true);
    document.removeEventListener('keydown', release, true);
    window.removeEventListener('blur', release);
    window.removeEventListener('pointercancel', release);
    element.removeEventListener('dragstart', release);
  };
  const released = (event: MouseEvent) => {
    if (event.button !== 0) return;
    // Mouse-up and click are separate events; let this click claim its response first.
    if (event.target instanceof Node && element.contains(event.target)) {
      clearTimeout(releaseTimer);
      releaseTimer = setTimeout(release, 0);
    } else release();
  };
  const click = (event: MouseEvent) => {
    const url = linkDestination(element, event);
    cancel();
    if (url) {
      event.preventDefault();
      void controller.visit(
        url.href,
        {
          preserveScroll: modifiers.includes('preserve-scroll'),
        },
        pressed?.url === url.href ? pressed.controller.signal : undefined,
      );
    }
    release();
  };
  element.addEventListener('click', click);
  let timer: ReturnType<typeof setTimeout> | undefined;
  const cancel = () => clearTimeout(timer);
  const press = (event: MouseEvent) => {
    release();
    const url = linkDestination(element, event);
    if (!url) return;
    cancel();
    const controllerForPress = new AbortController();
    pressed = { url: url.href, controller: controllerForPress };
    controller.prefetch(url.href, controllerForPress.signal);
    document.addEventListener('mouseup', released, true);
    document.addEventListener('mousedown', release, true);
    document.addEventListener('keydown', release, true);
    window.addEventListener('blur', release);
    window.addEventListener('pointercancel', release);
    element.addEventListener('dragstart', release);
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
    release();
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
