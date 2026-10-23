import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { postUpload } from '../../resources/ts/uploads/transport';

/**
 * Models native upload events at the HTTP boundary without simulating server behavior.
 *
 * Tests drive progress, abort, redirects, and response status independently of the
 * upload coordinator; real multipart parsing is covered by PHP and browser suites.
 */
class UploadRequest {
  static requests: UploadRequest[] = [];
  static throwOnOpen = false;
  status = 200;
  responseText = '{}';
  responseURL = 'http://localhost/upload';
  withCredentials = false;
  headers: Record<string, string> = {};
  data: FormData | undefined;
  contentType: string | null = 'application/json';
  interact: string | null = 'true';
  onload: (() => void) | null = null;
  onerror: (() => void) | null = null;
  ontimeout: (() => void) | null = null;
  onabort: (() => void) | null = null;
  upload: {
    onprogress:
      | ((event: {
          lengthComputable: boolean;
          total: number;
          loaded: number;
        }) => void)
      | null;
  } = { onprogress: null };
  constructor() {
    UploadRequest.requests.push(this);
  }
  open() {
    if (UploadRequest.throwOnOpen) throw new Error('Cannot open');
  }
  setRequestHeader(name: string, value: string) {
    this.headers[name] = value;
  }
  getResponseHeader(name: string) {
    return name === 'Interact' ? this.interact : this.contentType;
  }
  send(data: FormData) {
    this.data = data;
  }
  abort() {
    this.onabort?.();
  }
}

beforeEach(() => {
  UploadRequest.requests = [];
  UploadRequest.throwOnOpen = false;
  vi.stubGlobal('XMLHttpRequest', UploadRequest);
});
afterEach(() => vi.unstubAllGlobals());
function upload(controller = new AbortController()) {
  const progress = vi.fn<(value: number | null) => void>();
  const promise = postUpload(
    'http://localhost/upload',
    'token',
    { version: 1 },
    new File(['text'], 'notes.txt'),
    controller.signal,
    progress,
  );
  const request = UploadRequest.requests.at(-1);
  return { promise, progress, request, controller };
}

it('sends multipart data with credentials and CSRF while forwarding known and unknown progress', async () => {
  const value = upload();
  const request = value.request;
  if (!request) throw new Error('Missing XHR');
  expect(request.withCredentials).toBe(true);
  expect(request.headers).toEqual({
    Accept: 'application/json',
    Interact: 'true',
    'X-CSRF-Token': 'token',
  });
  expect(request.data?.get('payload')).toBe('{"version":1}');
  expect(request.data?.get('file')).toBeInstanceOf(File);
  request.upload.onprogress?.({ lengthComputable: true, total: 4, loaded: 2 });
  request.upload.onprogress?.({ lengthComputable: false, total: 0, loaded: 2 });
  expect(value.progress.mock.calls).toEqual([[50], [null]]);
  request.onload?.();
  const response = await value.promise;
  expect(response.status).toBe(200);
  expect(response.headers.get('Interact')).toBe('true');
  expect(request.onload).toBeNull();
});

it.each(['onerror', 'ontimeout'] as const)(
  'reports %s without automatic retry',
  async (event) => {
    const value = upload();
    value.request?.[event]?.();
    await expect(value.promise).rejects.toMatchObject({
      failure: { code: 'network_error' },
    });
    expect(UploadRequest.requests).toHaveLength(1);
  },
);

it('handles cancellation before and during the transfer', async () => {
  const early = new AbortController();
  early.abort();
  await expect(upload(early).promise).rejects.toMatchObject({
    failure: { code: 'cancelled' },
  });
  expect(UploadRequest.requests).toHaveLength(0);
  const active = upload();
  active.controller.abort();
  await expect(active.promise).rejects.toMatchObject({
    failure: { code: 'cancelled' },
  });
});

it('detects login redirects and zero-status responses', async () => {
  const redirected = upload();
  if (!redirected.request) throw new Error('Missing XHR');
  redirected.request.responseURL = 'http://localhost/login';
  redirected.request.onload?.();
  await expect(redirected.promise).rejects.toMatchObject({
    failure: { code: 'unexpected_redirect' },
  });
  const network = upload();
  if (!network.request) throw new Error('Missing XHR');
  network.request.status = 0;
  network.request.responseURL = '';
  network.request.onload?.();
  await expect(network.promise).rejects.toMatchObject({
    failure: { code: 'network_error' },
  });
});

it('settles construction errors and permits empty-body HTTP statuses for normal response validation', async () => {
  UploadRequest.throwOnOpen = true;
  await expect(upload().promise).rejects.toMatchObject({
    failure: { code: 'network_error' },
  });
  UploadRequest.throwOnOpen = false;
  const value = upload();
  if (!value.request) throw new Error('Missing XHR');
  value.request.status = 204;
  value.request.contentType = null;
  value.request.interact = null;
  value.request.onload?.();
  const response = await value.promise;
  expect(response.status).toBe(204);
  expect(response.headers.has('Interact')).toBe(false);
});
