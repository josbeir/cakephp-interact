import type { Json, State } from '../types';
import { forbidden } from './values';

/**
 * Parse a dotted property path and reject malformed or prototype-related segments.
 *
 * @throws Error For an invalid or unsafe state path.
 */
export function path(value: string): string[] {
  if (!/^[a-zA-Z_][a-zA-Z0-9_]*(?:\.[a-zA-Z0-9_-]+)*$/.test(value))
    throw new Error('Invalid state path.');
  const parts = value.split('.');
  if (parts.some((part) => forbidden.has(part)))
    throw new Error('Unsafe state path.');
  return parts;
}

/**
 * Read an owned nested value without traversing inherited object properties.
 */
export function read(value: State, name: string): Json | undefined {
  let current: Json | undefined = value;
  for (const part of path(name)) {
    if (
      current === null ||
      typeof current !== 'object' ||
      !Object.hasOwn(current, part)
    )
      return undefined;
    current = Array.isArray(current) ? current[Number(part)] : current[part];
  }
  return current;
}

/**
 * Apply a local draft at a validated nested path.
 *
 * Missing containers follow the next path segment. Named edits convert PHP-style
 * empty arrays into records so JSON serialization retains those values.
 *
 * @throws Error If a path is unsafe or attempts to traverse scalar state.
 */
export function write(value: State, name: string, replacement: Json): void {
  const parts = path(name);
  let current: Record<string, Json> | Json[] = value;
  for (const [index, part] of parts.entries()) {
    if (index === parts.length - 1) {
      assign(current, part, replacement);
      return;
    }
    let child: Json | undefined = Reflect.get(current, part);
    if (child === undefined) {
      child = /^\d+$/.test(parts[index + 1] ?? '') ? [] : {};
      assign(current, part, child);
    }
    // PHP's empty array can become a record once an application binds a named field.
    const next = parts[index + 1] ?? '';
    if (Array.isArray(child) && !isArrayIndex(next)) {
      const fields: Record<string, Json> = {};
      child.forEach((entry, index) => {
        fields[String(index)] = entry;
      });
      child = fields;
      assign(current, part, child);
    }
    if (child === null || typeof child !== 'object')
      throw new Error('Cannot traverse scalar state.');
    current = child;
  }
}

/**
 * Recognize canonical JSON-list indices rather than array properties or leading-zero keys.
 */
function isArrayIndex(value: string): boolean {
  const number = Number(value);
  return (
    Number.isInteger(number) &&
    number >= 0 &&
    number < 4294967295 &&
    String(number) === value
  );
}

/**
 * Use the proxy's set trap so Alpine observes edits, including newly created containers.
 */
function assign(
  target: Record<string, Json> | Json[],
  key: string,
  value: Json,
): void {
  if (!Reflect.set(target, key, value)) throw new Error('Cannot update state.');
}
