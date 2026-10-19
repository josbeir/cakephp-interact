/**
 * Parse server markup into a single root belonging to the expected island.
 *
 * Application templates remain responsible for escaping; this is an ownership
 * check, not an HTML sanitizer.
 *
 * @throws Error If the response would replace another island or introduce multiple roots.
 */
export function readRoot(html: string, id: string): HTMLElement {
  const template = document.createElement('template');
  template.innerHTML = html.trim();
  const replacement = template.content.firstElementChild;
  if (
    template.content.childElementCount !== 1 ||
    !(replacement instanceof HTMLElement) ||
    replacement.dataset.interactId !== id
  )
    throw new Error('Invalid island markup.');
  return replacement;
}
