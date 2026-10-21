import type { LoadingMode } from '../loading/trigger';
import { urlFields, urlState } from '../url/codec';
import type { UrlFields } from '../url/types';
import { uploadMetadata } from '../uploads/protocol';
import type { UploadMetadata } from '../uploads/types';
import type { State, Writable } from '../types';
import { state } from '../state/values';
import { writable } from './protocol';

/**
 * Validated bootstrap data emitted by PHP for one owned island root.
 */
export interface MountMetadata {
  loading?: LoadingMode;
  id: string;
  snapshot: string;
  state: State;
  writable: Writable;
  endpoint: string;
  csrf: string;
  uploads: UploadMetadata;
  url: UrlFields;
}

/**
 * Read initial root attributes and reject malformed state or a cross-origin endpoint.
 *
 * @throws Error When the markup cannot safely initialize an island client.
 */
export function readMetadata(root: HTMLElement): MountMetadata {
  const loading = root.dataset.interactLoading;
  if (
    loading !== undefined &&
    loading !== 'lazy' &&
    loading !== 'defer' &&
    loading !== 'manual'
  )
    throw new Error('Invalid initial loading mode.');
  const url: unknown = JSON.parse(root.dataset.interactUrl ?? '{}');
  if (!urlFields(url)) throw new Error('Invalid initial URL metadata.');
  const uploads: unknown = JSON.parse(root.dataset.interactUploads ?? '{}');
  if (!uploadMetadata(uploads))
    throw new Error('Invalid initial upload metadata.');
  const initial: unknown = JSON.parse(root.dataset.interactState ?? '{}');
  const fields: unknown = JSON.parse(root.dataset.interactWritable ?? '{}');
  if (
    !state(initial) ||
    !writable(fields) ||
    (loading === undefined
      ? !urlState(initial, url)
      : Object.keys(initial).length > 0 ||
        Object.keys(fields).length > 0 ||
        Object.keys(uploads).length > 0)
  )
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
    ...(loading === undefined ? {} : { loading }),
    uploads,
    url,
    id,
    snapshot,
    state: initial,
    writable: fields,
    endpoint: endpoint.href,
    csrf: root.dataset.interactCsrf ?? '',
  };
}
