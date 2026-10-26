import { destination, redirect } from '../effects/browser';
import { urlFields, urlState } from '../url/codec';
import { uploadMetadata } from '../uploads/protocol';
import type { UpdateResponse, Writable } from '../types';
import { validPath } from '../state/paths';
import { record, state } from '../state/values';
import { diagnosticEnvelope } from '../diagnostics/output';
import { realtimeMetadata } from '../realtime/protocol';
import { actionListeners } from '../events/actions';

/**
 * Validate browser-write metadata against the supported native PHP property types.
 */
export function writable(value: unknown): value is Writable {
  return (
    record(value) &&
    Object.entries(value).every(
      ([key, entry]) =>
        validPath(key) &&
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
  if (!diagnosticEnvelope(value)) return false;
  if (record(value) && 'redirect' in value)
    return (
      Object.keys(value).length === ('diagnostics' in value ? 4 : 3) &&
      value.version === 1 &&
      typeof value.id === 'string' &&
      redirect(value.redirect)
    );
  return (
    record(value) &&
    value.version === 1 &&
    (value.listeners === undefined || actionListeners(value.listeners)) &&
    (value.realtime === undefined || realtimeMetadata(value.realtime)) &&
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

/** Match a validated result to its island and the status carrying its effects. */
export function responseMatches(
  value: unknown,
  id: string,
  status: number,
): value is UpdateResponse {
  return (
    response(value) &&
    value.id === id &&
    ((!('redirect' in value) && value.html !== null) || status === 200)
  );
}
