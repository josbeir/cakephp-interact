import { path } from '../state/paths';
import { record } from '../state/values';
import type { Relation } from './types';

/** Validate runtime-owned relationship metadata before connecting any reactive state. */
export function relation(value: unknown): value is Relation {
  if (
    !record(value) ||
    typeof value.parentId !== 'string' ||
    !value.parentId ||
    !record(value.models)
  )
    return false;
  return Object.entries(value.models).every(([name, binding]) => {
    if (
      !record(binding) ||
      typeof binding.path !== 'string' ||
      typeof binding.timing !== 'string' ||
      !['defer', 'live', 'change', 'blur'].includes(binding.timing) ||
      (binding.delay !== undefined &&
        (typeof binding.delay !== 'number' ||
          !Number.isInteger(binding.delay) ||
          binding.delay < 0 ||
          binding.delay > 60000)) ||
      (binding.delay === undefined && binding.timing !== 'live')
    )
      return false;
    return path(name).length === 1 && path(binding.path).length > 0;
  });
}
