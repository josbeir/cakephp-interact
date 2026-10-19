import type { IslandResponse, Writable } from '../types';
import { forbidden, record, state } from '../state/values';
/**
 * Validate browser-write metadata against the supported native PHP property types.
 */
export function writable(value: unknown): value is Writable {
  return (
    record(value) &&
    Object.entries(value).every(
      ([key, entry]) =>
        !forbidden.has(key) &&
        record(entry) &&
        typeof entry.type === 'string' &&
        ['string', 'int', 'float', 'bool', 'array'].includes(entry.type) &&
        typeof entry.nullable === 'boolean',
    )
  );
}
/**
 * Check the complete update envelope before any server state or effects are applied.
 */
export function response(value: unknown): value is IslandResponse {
  return (
    record(value) &&
    value.version === 1 &&
    typeof value.id === 'string' &&
    typeof value.html === 'string' &&
    typeof value.snapshot === 'string' &&
    value.snapshot.length > 0 &&
    state(value.state) &&
    writable(value.writable) &&
    record(value.errors) &&
    Object.values(value.errors).every(
      (entry) =>
        Array.isArray(entry) &&
        entry.every((message: unknown) => typeof message === 'string'),
    ) &&
    Array.isArray(value.events) &&
    value.events.every(
      (event: unknown) =>
        record(event) &&
        typeof event.name === 'string' &&
        /^[a-z][a-z0-9:-]*$/.test(event.name) &&
        state(event.detail),
    )
  );
}
