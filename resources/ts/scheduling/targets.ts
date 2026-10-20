import { json, record } from '../state/values';
import type { Invocation, Json, LoadingTarget, RequestTarget } from '../types';

/**
 * Normalize request selectors shared by immediate and delayed activity queries.
 */
export function target(
  selector: string | null | LoadingTarget | undefined,
  args: Json[],
): LoadingTarget {
  const result =
    typeof selector === 'object' && selector !== null
      ? { ...selector }
      : selector === undefined
        ? {}
        : { action: selector, ...(args.length ? { args } : {}) };
  if (
    (result.action !== undefined &&
      result.action !== null &&
      typeof result.action !== 'string') ||
    (result.args !== undefined &&
      (typeof result.action !== 'string' ||
        !Array.isArray(result.args) ||
        !json(result.args))) ||
    (result.delay !== undefined &&
      (!Number.isInteger(result.delay) ||
        result.delay < 0 ||
        result.delay > 60000))
  )
    throw new Error('Invalid request activity target.');
  if (
    args.length &&
    (selector === undefined ||
      selector === null ||
      typeof selector === 'object')
  )
    throw new Error('Arguments require an action target.');
  return {
    ...(result.action === undefined ? {} : { action: result.action }),
    ...(result.args === undefined ? {} : { args: copyArguments(result.args) }),
    ...(result.delay === undefined ? {} : { delay: result.delay }),
  };
}

/**
 * Compare JSON tuples independently of record key order, retaining scalar types.
 */
export function canonical(value: Json): string {
  if (Array.isArray(value)) return `[${value.map(canonical).join(',')}]`;
  if (record(value))
    return `{${Object.keys(value)
      .sort()
      .map((key) => `${JSON.stringify(key)}:${canonical(value[key] ?? null)}`)
      .join(',')}}`;
  return JSON.stringify(value);
}

/**
 * Match a complete argument tuple, or all invocations of an unqualified action.
 */
export function matches(query: RequestTarget, invocation: Invocation): boolean {
  return (
    (query.action === undefined || query.action === invocation.action) &&
    (query.args === undefined ||
      canonical(query.args) === canonical(invocation.args))
  );
}

/**
 * Validate and detach action arguments before accepting work into the queue.
 */
export function copyArguments(args: Json[]): Json[] {
  if (!json(args)) throw new Error('Invalid action arguments.');
  const copy: unknown = JSON.parse(JSON.stringify(args));
  if (!Array.isArray(copy) || !json(copy))
    throw new Error('Invalid action arguments.');
  return copy;
}
