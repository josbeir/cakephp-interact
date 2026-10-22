import { destination } from '../effects/browser';

/** Only ordinary same-document-context HTTP links participate in enhanced visits. */
export function linkDestination(
  link: HTMLAnchorElement,
  event?: MouseEvent,
): URL | undefined {
  if (
    (event &&
      (event.defaultPrevented ||
        event.button !== 0 ||
        event.metaKey ||
        event.ctrlKey ||
        event.shiftKey ||
        event.altKey)) ||
    link.hasAttribute('download') ||
    (link.target && link.target.toLowerCase() !== '_self') ||
    link.relList.contains('external') ||
    !link.hasAttribute('href') ||
    !destination(link.href, true)
  )
    return;
  const url = new URL(link.href);
  if (
    url.pathname === window.location.pathname &&
    url.search === window.location.search &&
    (url.hash || window.location.hash)
  )
    return;
  return url;
}

/** Mark the exact page and optional URL ancestors independently of queries and fragments. */
export function updateCurrent(link: HTMLAnchorElement, trail = false): void {
  const url = new URL(link.href, window.location.href);
  const path = (value: string) => value.replace(/\/+$/, '') || '/';
  const linkPath = path(url.pathname);
  const currentPath = path(window.location.pathname);
  const sameOrigin = url.origin === window.location.origin;
  const current = sameOrigin && linkPath === currentPath;
  if (current) link.setAttribute('aria-current', 'page');
  else if (link.getAttribute('aria-current') === 'page')
    link.removeAttribute('aria-current');
  link.toggleAttribute(
    'data-interact-active-trail',
    trail &&
      sameOrigin &&
      (current || (linkPath !== '/' && currentPath.startsWith(`${linkPath}/`))),
  );
}
