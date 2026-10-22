import { destination, redirect } from '../effects/browser';
import { urlFields, urlState } from '../url/codec';
import { uploadMetadata } from '../uploads/protocol';
import type { UpdateResponse, Writable } from '../types';
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
export function response(value: unknown): value is UpdateResponse {
  if (record(value) && 'redirect' in value)
    return (
      Object.keys(value).length === 3 &&
      value.version === 1 &&
      typeof value.id === 'string' &&
      redirect(value.redirect)
    );
  return (
    record(value) &&
    value.version === 1 &&
    (value.url === undefined || urlFields(value.url)) &&
    (value.uploads === undefined || uploadMetadata(value.uploads)) &&
    typeof value.id === 'string' &&
    (typeof value.html === 'string' || value.html === null) &&
    typeof value.snapshot === 'string' &&
    value.snapshot.length > 0 &&
    state(value.state) &&
    (value.url === undefined || urlState(value.state, value.url)) &&
    writable(value.writable) &&
    (value.download === undefined ||
      (destination(value.download, true) &&
        record(value.errors) &&
        Object.keys(value.errors).length === 0)) &&
    record(value.errors) &&
    Object.values(value.errors).every(
      (entry) =>
        Array.isArray(entry) &&
        entry.every((message: unknown) => typeof message === 'string'),
    ) &&
    (value.html !== null || Object.keys(value.errors).length === 0) &&
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
