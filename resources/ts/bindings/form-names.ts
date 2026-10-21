import { path } from '../state/paths';

/**
 * Resolve a native form name relative to its literal array-state path.
 *
 * Empty brackets describe a list leaf, never an implicit row index. Bookkeeping
 * fields belong to CakePHP's native request handling rather than island drafts.
 */
export function formName(
  base: string,
  name: string,
): { path: string; list: boolean } | null {
  if (!name) return null;
  const list = name.endsWith('[]');
  const source = list ? name.slice(0, -2) : name;
  if (
    !/^[a-zA-Z_][a-zA-Z0-9_]*(?:(?:\.[a-zA-Z0-9_-]+)|(?:\[[a-zA-Z0-9_-]+\]))*$/.test(
      source,
    )
  )
    throw new Error('Invalid or ambiguous form control name.');
  const relative = source.replace(/\[([a-zA-Z0-9_-]+)\]/g, '.$1');
  const parts = path(relative);
  if (['_csrfToken', '_Token', '_method'].includes(parts[0] ?? '')) return null;
  const resolved = `${base}.${relative}`;
  path(resolved);
  return { path: resolved, list };
}
