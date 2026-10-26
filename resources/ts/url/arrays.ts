import { forbidden, record } from '../state/values';
import type { UrlArray } from './types';

const identifier = /^[a-zA-Z_][a-zA-Z0-9_-]*$/;

/** Keep query names and map keys literal, safe, and independent of PHP normalization. */
export function queryName(name: string): boolean {
  return name === name.trim() && identifier.test(name) && !forbidden.has(name);
}

/** Reserve the owning state-record level while validating string query structures. */
export function stringTree(
  value: unknown,
  depth = 1,
): value is string | UrlArray {
  if (depth > 32) return false;
  if (typeof value === 'string') return true;
  if (Array.isArray(value))
    return value.every((entry: unknown) => stringTree(entry, depth + 1));
  return (
    record(value) &&
    Object.keys(value).length > 0 &&
    Object.entries(value).every(
      ([key, entry]) => queryName(key) && stringTree(entry, depth + 1),
    )
  );
}

/** Merge partial maps; supplied lists and explicit empty arrays replace their defaults. */
export function mergeMaps(value: UrlArray, defaults: UrlArray): UrlArray {
  if (Array.isArray(value) || Array.isArray(defaults)) return value;
  const merged = structuredClone(defaults);
  for (const [key, entry] of Object.entries(value)) {
    const previous = defaults[key];
    merged[key] =
      typeof entry !== 'string' &&
      previous !== undefined &&
      typeof previous !== 'string'
        ? mergeMaps(entry, previous)
        : entry;
  }
  return merged;
}
