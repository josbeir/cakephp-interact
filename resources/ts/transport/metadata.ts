import { uploadMetadata } from '../uploads/protocol';
import type { UploadMetadata } from '../uploads/types';
import type { State, Writable } from '../types';
import { state } from '../state/values';
import { writable } from './protocol';

/**
 * Validated bootstrap data emitted by PHP for one owned island root.
 */
export interface MountMetadata {
  id: string;
  snapshot: string;
  state: State;
  writable: Writable;
  endpoint: string;
  csrf: string;
  uploads: UploadMetadata;
}

/**
 * Read initial root attributes and reject malformed state or a cross-origin endpoint.
 *
 * @throws Error When the markup cannot safely initialize an island client.
 */
export function readMetadata(root: HTMLElement): MountMetadata {
  const uploads: unknown = JSON.parse(root.dataset.interactUploads ?? '{}');
  if (!uploadMetadata(uploads))
    throw new Error('Invalid initial upload metadata.');
  const initial: unknown = JSON.parse(root.dataset.interactState ?? '{}');
  const fields: unknown = JSON.parse(root.dataset.interactWritable ?? '{}');
  if (!state(initial) || !writable(fields))
    throw new Error('Invalid initial island state.');
  const id = root.dataset.interactId ?? '';
  const snapshot = root.dataset.interactSnapshot ?? '';
  if (!id || !snapshot) throw new Error('Missing island identity.');
  const endpoint = new URL(
    root.dataset.interactEndpoint ?? '',
    window.location.href,
  );
  if (endpoint.origin !== window.location.origin)
    throw new Error('Island endpoints must be same-origin.');
  return {
    uploads,
    id,
    snapshot,
    state: initial,
    writable: fields,
    endpoint: endpoint.href,
    csrf: root.dataset.interactCsrf ?? '',
  };
}
