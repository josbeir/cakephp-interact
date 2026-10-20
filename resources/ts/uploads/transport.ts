import type { Uploader } from './types';
import { requestError } from '../transport/failure';

/**
 * Transfer multipart bytes through the existing endpoint with native upload progress.
 *
 * XMLHttpRequest is limited to file transfer; normal island updates retain fetch.
 * A completed byte count is not accepted until the response passes normal validation.
 */
export const postUpload: Uploader = (
  endpoint,
  csrf,
  payload,
  file,
  signal,
  progress,
) =>
  new Promise((resolve, reject) => {
    if (signal.aborted) {
      reject(requestError('cancelled', null));
      return;
    }
    const xhr = new XMLHttpRequest();
    const abort = () => xhr.abort();
    const cleanup = () => {
      signal.removeEventListener('abort', abort);
      xhr.onload = xhr.onerror = xhr.onabort = xhr.ontimeout = null;
      xhr.upload.onprogress = null;
    };
    xhr.upload.onprogress = (event) =>
      progress(
        event.lengthComputable && event.total > 0
          ? Math.min(100, Math.round((event.loaded / event.total) * 100))
          : null,
      );
    xhr.onload = () => {
      cleanup();
      if (
        xhr.responseURL &&
        new URL(xhr.responseURL).href !== new URL(endpoint).href
      ) {
        reject(requestError('unexpected_redirect', xhr.status || null));
        return;
      }
      if (xhr.status === 0) {
        reject(requestError('network_error', null));
        return;
      }
      resolve(
        new Response(
          [204, 205, 304].includes(xhr.status) ? null : xhr.responseText,
          {
            status: xhr.status,
            headers: {
              'Content-Type': xhr.getResponseHeader('Content-Type') ?? '',
            },
          },
        ),
      );
    };
    xhr.onerror = xhr.ontimeout = () => {
      cleanup();
      reject(requestError('network_error', null));
    };
    xhr.onabort = () => {
      cleanup();
      reject(requestError('cancelled', null));
    };
    try {
      xhr.open('POST', endpoint);
      xhr.withCredentials = true;
      xhr.setRequestHeader('Accept', 'application/json');
      xhr.setRequestHeader('X-CSRF-Token', csrf);
      signal.addEventListener('abort', abort, { once: true });
      const data = new FormData();
      data.set('payload', JSON.stringify(payload));
      data.set('file', file);
      xhr.send(data);
    } catch (cause: unknown) {
      cleanup();
      reject(requestError('network_error', null, cause));
    }
  });
