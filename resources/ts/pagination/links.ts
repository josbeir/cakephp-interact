import { linkDestination } from '../navigation/links';
import { record } from '../state/values';
import type { State, Writable } from '../types';
import type { UrlFields } from '../url/types';

/**
 * Enhance only helper-generated links owned by this island, independently of template markup.
 * Native navigation retains modified clicks, explicit destinations, and application handlers.
 */
export function paginationLinks(
  root: HTMLElement,
  fields: UrlFields,
  writable: () => Writable,
  apply: (changes: State) => void,
  failed: (cause: unknown) => void,
): () => void {
  const click = (event: MouseEvent) => {
    if (!(event.target instanceof Element)) return;
    const link = event.target.closest('a');
    if (
      !(link instanceof HTMLAnchorElement) ||
      link.closest('[data-interact-id]') !== root ||
      [...link.attributes].some((attribute) =>
        attribute.name.startsWith('x-interact:navigate'),
      )
    )
      return;
    const destination = linkDestination(link, event);
    if (
      !destination ||
      destination.origin !== window.location.origin ||
      destination.pathname !== window.location.pathname ||
      destination.hash
    )
      return;
    try {
      for (const marker of root.querySelectorAll(
        'template[data-interact-pagination]',
      )) {
        if (marker.closest('[data-interact-id]') !== root) continue;
        const targets: unknown = JSON.parse(
          marker.getAttribute('data-interact-pagination') ?? '{}',
        );
        if (!record(targets)) throw new Error('Invalid pagination targets.');
        const changes = targets[destination.pathname + destination.search];
        if (changes === undefined) continue;
        if (!pagingChanges(changes, fields, writable()))
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

/** Validate generated edits against the same explicit URL and writable declarations as models. */
function pagingChanges(
  value: unknown,
  fields: UrlFields,
  writable: Writable,
): value is State {
  return (
    record(value) &&
    typeof value.page === 'number' &&
    Object.entries(value).every(([key, item]) => {
      const type = key === 'page' || key === 'limit' ? 'int' : 'string';
      return (
        ['page', 'sort', 'direction', 'limit'].includes(key) &&
        fields[key]?.type === type &&
        writable[key]?.type === type &&
        (type === 'int'
          ? typeof item === 'number' && Number.isSafeInteger(item) && item > 0
          : typeof item === 'string')
      );
    })
  );
}
