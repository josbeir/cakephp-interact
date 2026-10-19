import type { State, Writable } from '../types';
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
    const value = current[name];
    if (value !== undefined && !equal(value, baseline[name]))
      changes[name] = value;
  }
  return changes;
}
