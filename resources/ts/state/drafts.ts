import type { Json, State, Writable } from '../types';
import { read } from './paths';
import { equal } from './values';

/**
 * Select writable values that differ from a baseline without coercing scalar types.
 *
 * Used both to prepare edits and to retain drafts changed after a request began.
 */
export function changedWritable(
  current: State,
  baseline: State,
  fields: Writable,
): State {
  const changes: State = {};
  for (const name of Object.keys(fields)) {
    const value = read(current, name);
    if (value !== undefined && !equal(value, read(baseline, name)))
      changes[name] = value;
  }
  return changes;
}

/**
 * Retain newer writable edits while accepting independent server changes within maps.
 *
 * Lists remain atomic on conflicting edits, except for server-only appends to an
 * unchanged baseline prefix. No row identity is inferred from array indices.
 */
export function newerWritable(
  current: State,
  sent: State,
  accepted: State,
  fields: Writable,
): State {
  const changes = changedWritable(current, sent, fields);
  for (const name of Object.keys(changes)) {
    const value = reconcile(
      read(current, name),
      read(sent, name),
      read(accepted, name),
    );
    if (value !== undefined) changes[name] = value;
  }
  return changes;
}

/** Merge independent map edits; newer local values own conflicts and explicit removals. */
function reconcile(
  current: Json | undefined,
  sent: Json | undefined,
  accepted: Json | undefined,
): Json | undefined {
  if (equal(current, sent)) return accepted;
  if (
    current !== null &&
    typeof current === 'object' &&
    !Array.isArray(current) &&
    accepted !== null &&
    typeof accepted === 'object' &&
    !Array.isArray(accepted) &&
    sent !== null &&
    typeof sent === 'object' &&
    (!Array.isArray(sent) || sent.length === 0)
  ) {
    const baseline = Array.isArray(sent) ? {} : sent;
    const merged: State = { ...accepted };
    for (const key of new Set([
      ...Object.keys(baseline),
      ...Object.keys(current),
    ])) {
      const value = reconcile(
        Object.hasOwn(current, key) ? current[key] : undefined,
        Object.hasOwn(baseline, key) ? baseline[key] : undefined,
        Object.hasOwn(accepted, key) ? accepted[key] : undefined,
      );
      if (value === undefined) delete merged[key];
      else merged[key] = value;
    }
    return merged;
  }
  if (
    Array.isArray(current) &&
    Array.isArray(sent) &&
    Array.isArray(accepted) &&
    current.length === sent.length &&
    accepted.length >= sent.length &&
    sent.every((value, index) => equal(value, accepted[index]))
  )
    return [...current, ...accepted.slice(sent.length)];
  return current;
}
