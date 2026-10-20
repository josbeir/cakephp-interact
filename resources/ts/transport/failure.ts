import type { FailureCode, RequestFailure } from '../types';
import { record } from '../state/values';

const definitions: Record<
  FailureCode,
  { recovery: RequestFailure['recovery']; message: string }
> = {
  snapshot_expired: {
    recovery: 'reload',
    message: 'This page has expired. Preserve your draft, then reload.',
  },
  session_changed: {
    recovery: 'reload',
    message: 'Your session has changed. Preserve your draft, then reload.',
  },
  invalid_snapshot: {
    recovery: 'reload',
    message:
      'This page can no longer update. Preserve your draft, then reload.',
  },
  unauthenticated: {
    recovery: 'authenticate',
    message: 'Sign in again, then reload this page.',
  },
  csrf_failed: {
    recovery: 'reload',
    message:
      'The security token is no longer valid. Preserve your draft, then reload.',
  },
  forbidden: {
    recovery: 'none',
    message: 'You no longer have permission to perform this operation.',
  },
  invalid_request: {
    recovery: 'retry',
    message:
      'The update was rejected. Check your input before submitting again.',
  },
  upload_too_large: {
    recovery: 'retry',
    message: 'The upload exceeds the server size limit. Choose a smaller file.',
  },
  server_error: {
    recovery: 'retry',
    message:
      'The server could not complete the response. Check the result before trying again.',
  },
  http_error: {
    recovery: 'none',
    message: 'The update could not be completed.',
  },
  network_error: {
    recovery: 'retry',
    message: 'The connection failed. Check the result before trying again.',
  },
  unexpected_redirect: {
    recovery: 'reload',
    message:
      'The update redirected unexpectedly. Preserve your draft, then reload.',
  },
  invalid_response: {
    recovery: 'reload',
    message:
      'The server returned an invalid update. Preserve your draft, then reload.',
  },
  cancelled: {
    recovery: 'none',
    message:
      'The island was removed. The server operation may still have completed.',
  },
  client_error: {
    recovery: 'reload',
    message:
      'The page could not apply the update. Preserve your draft, then reload.',
  },
};

const serverStatuses = {
  snapshot_expired: 409,
  session_changed: 409,
  invalid_snapshot: 409,
  unauthenticated: 401,
  csrf_failed: 403,
  forbidden: 403,
  invalid_request: 400,
  upload_too_large: 413,
  server_error: 500,
  http_error: 0,
};
type ServerCode = keyof typeof serverStatuses;

/**
 * Rejects a request with the same safe failure data exposed through Alpine and events.
 *
 * The original cause remains available for debugging without becoming user-facing text.
 */
export class IslandRequestError extends Error {
  /**
   * Keep the public failure and optional diagnostic cause on one rejected request.
   */
  constructor(
    readonly failure: RequestFailure,
    options?: ErrorOptions,
  ) {
    super(failure.message, options);
    this.name = 'IslandRequestError';
  }
}

/**
 * Build local fallback feedback without reflecting untrusted response or exception text.
 */
export function requestError(
  code: FailureCode,
  status: number | null,
  cause?: unknown,
): IslandRequestError {
  return new IslandRequestError(
    { code, status, ...definitions[code] },
    { cause },
  );
}

/**
 * Classify an HTTP failure even when application middleware returns its own error document.
 */
export function httpCode(status: number): ServerCode {
  if (status >= 500) return 'server_error';
  if (status === 400) return 'invalid_request';
  if (status === 413) return 'upload_too_large';
  if (status === 401) return 'unauthenticated';
  if (status === 403) return 'forbidden';
  return 'http_error';
}

/**
 * Recognize only declared server codes before indexing their expected HTTP statuses.
 */
function serverCode(value: unknown): value is ServerCode {
  return typeof value === 'string' && Object.hasOwn(serverStatuses, value);
}

/**
 * Validate versioned server feedback; legacy or HTML responses use safe status fallbacks.
 */
export async function readFailure(
  result: Response,
): Promise<IslandRequestError> {
  const fallback = requestError(httpCode(result.status), result.status);
  if (!result.headers.get('content-type')?.includes('application/json'))
    return fallback;
  let value: unknown;
  try {
    value = await result.json();
  } catch {
    return fallback;
  }
  if (!record(value) || value.version !== 1) return fallback;
  const error = value.error;
  if (
    !record(error) ||
    !serverCode(error.code) ||
    typeof error.message !== 'string' ||
    error.message.length === 0
  )
    return requestError('invalid_response', result.status);
  const expected = serverStatuses[error.code];
  if (
    (error.code === 'server_error' && result.status < 500) ||
    (error.code === 'http_error' &&
      (result.status < 400 ||
        result.status >= 500 ||
        [400, 401, 403, 413].includes(result.status))) ||
    (expected !== 0 &&
      error.code !== 'server_error' &&
      expected !== result.status)
  )
    return requestError('invalid_response', result.status);
  return new IslandRequestError({
    code: error.code,
    message: error.message,
    status: result.status,
    recovery: definitions[error.code].recovery,
  });
}
