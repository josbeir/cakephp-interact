import { linkDestination } from '../navigation/links';
import { ownsIslandElement } from '../dom/ownership';
import { record } from '../state/values';
import type { State, Writable } from '../types';

/**
 * Enhance only helper-generated links owned by this island, independently of template markup.
 * Native navigation retains modified clicks, explicit destinations, and application handlers.
 */
export function paginationLinks(
  root: HTMLElement,
  writable: () => Writable,
  apply: (changes: State) => void,
  failed: (cause: unknown) => void,
): () => void {
  const click = (event: MouseEvent) => {
    if (!(event.target instanceof Element)) return;
    const link = event.target.closest('a');
    if (
      !(link instanceof HTMLAnchorElement) ||
      !ownsIslandElement(root, link) ||
      [...link.attributes].some((attribute) =>
        attribute.name.startsWith('x-interact:navigate'),
      )
    )
      return;
    const destination = linkDestination(link, event, { fragments: true });
    if (
      !destination ||
      destination.origin !== window.location.origin ||
      destination.pathname !== window.location.pathname ||
      (destination.hash &&
        !destination.hash.startsWith('#interact-pagination-'))
    )
      return;
    try {
      for (const marker of root.querySelectorAll<HTMLTemplateElement>(
        'template[data-interact-pagination]',
      )) {
        if (!ownsIslandElement(root, marker)) continue;
        const targets: unknown = JSON.parse(
          marker.getAttribute('data-interact-pagination') ?? '{}',
        );
        if (!record(targets)) throw new Error('Invalid pagination targets.');
        const changes =
          targets[destination.pathname + destination.search + destination.hash];
        if (changes === undefined) continue;
        if (!pagingChanges(changes, writable()))
          throw new Error('Invalid pagination state.');
        event.preventDefault();
        apply(changes);
        return;
      }
    } catch (cause: unknown) {
      event.preventDefault();
      failed(cause);
    }
  };
  root.addEventListener('click', click);
  return () => root.removeEventListener('click', click);
}

/** Validate paging edits against explicit writable declarations without requiring URL ownership. */
function pagingChanges(value: unknown, writable: Writable): value is State {
  if (!record(value)) return false;
  const entries = Object.entries(value);
  const pages = entries.filter(([key]) => key.split('.').at(-1) === 'page');
  const page = pages[0];
  if (pages.length !== 1 || !page) return false;
  const owner = page[0].split('.').slice(0, -1).join('.');
  return entries.every(([key, item]) => {
    const parts = key.split('.');
    const name = parts.pop();
    const type = name === 'page' || name === 'limit' ? 'int' : 'string';
    return (
      parts.join('.') === owner &&
      name !== undefined &&
      ['page', 'sort', 'direction', 'limit'].includes(name) &&
      writable[key]?.type === type &&
      (type === 'int'
        ? typeof item === 'number' && Number.isSafeInteger(item) && item > 0
        : typeof item === 'string')
    );
  });
}
