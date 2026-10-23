import type { Json, State } from '../types';

/**
 * Exclude property names that could traverse or mutate object prototypes.
 */
export const forbidden = new Set(['__proto__', 'prototype', 'constructor']);

/**
 * Recognize plain records without accepting class instances or arrays.
 */
export function record(value: unknown): value is Record<string, unknown> {
  return (
    typeof value === 'object' &&
    value !== null &&
    (Object.getPrototypeOf(value) === Object.prototype ||
      Object.getPrototypeOf(value) === null)
  );
}

/**
 * Check the finite JSON value vocabulary and reject unsafe keys or excessive nesting.
 *
 * This guard validates without coercing dates, functions, non-finite numbers, or objects.
 */
export function json(value: unknown, depth = 0): value is Json {
  if (depth > 32) return false;
  if (value === null || typeof value === 'string' || typeof value === 'boolean')
    return true;
  if (typeof value === 'number') return Number.isFinite(value);
  if (Array.isArray(value))
    return value.every((entry: unknown) => json(entry, depth + 1));
  return (
    record(value) &&
    Object.entries(value).every(
      ([key, entry]) => !forbidden.has(key) && json(entry, depth + 1),
    )
  );
}

/**
 * Require a plain property record containing only supported JSON values.
 */
export function state(value: unknown): value is State {
  return record(value) && json(value);
}

/**
 * Copy validated state through JSON without sharing mutable nested values.
 *
 * @throws Error When the draft contains unsupported transport values.
 */
export function clone(value: State): State {
  if (!state(value)) throw new Error('Invalid state.');
  const decoded: unknown = JSON.parse(JSON.stringify(value));
  if (!state(decoded)) throw new Error('Invalid state.');
  return decoded;
}

/**
 * Compare transport values while keeping missing values distinct from explicit null.
 *
 * Objects are traversed even at identical references so Alpine tracks nested edits.
 */
export function equal(
  left: Json | undefined,
  right: Json | undefined,
): boolean {
  if (
    (left === null || typeof left !== 'object') &&
    (right === null || typeof right !== 'object')
  )
    return left === right;
  return JSON.stringify(left) === JSON.stringify(right);
}
