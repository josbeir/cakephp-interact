import { describe, expect, it } from 'vite-plus/test';
import {
  IslandRequestError,
  readFailure,
  requestError,
} from '../../resources/ts/transport/failure';
import { readResponse } from '../../resources/ts/transport/request';
import type { FailureCode, RequestFailure } from '../../resources/ts/types';

function reply(value: unknown, status: number): Response {
  return new Response(JSON.stringify(value), {
    status,
    headers: { 'Content-Type': 'application/json' },
  });
}

describe('safe request failures', () => {
  const categories: [FailureCode, number, RequestFailure['recovery']][] = [
    ['snapshot_expired', 409, 'reload'],
    ['session_changed', 409, 'reload'],
    ['invalid_snapshot', 409, 'reload'],
    ['unauthenticated', 401, 'authenticate'],
    ['csrf_failed', 403, 'reload'],
    ['forbidden', 403, 'none'],
    ['invalid_request', 400, 'retry'],
    ['server_error', 503, 'retry'],
    ['http_error', 429, 'none'],
  ];
  it.each(categories)(
    'reads %s without guessing from text',
    async (code, status, recovery) => {
      const error = await readFailure(
        reply(
          { version: 1, error: { code, message: 'Safe translated feedback' } },
          status,
        ),
      );
      expect(error).toBeInstanceOf(IslandRequestError);
      expect(error.failure).toEqual({
        code,
        status,
        recovery,
        message: 'Safe translated feedback',
      });
      expect(error.message).toBe(error.failure.message);
    },
  );
  it.each([
    [400, 'invalid_request'],
    [401, 'unauthenticated'],
    [403, 'forbidden'],
    [404, 'http_error'],
    [500, 'server_error'],
  ])('classifies legacy or HTML status %s safely', async (status, code) => {
    if (typeof status !== 'number') throw new Error('Invalid test status');
    for (const response of [
      reply({ error: '<script>private</script>' }, status),
      new Response('private', { status }),
      reply({ version: 2 }, status),
      new Response('{', {
        status,
        headers: { 'Content-Type': 'application/json' },
      }),
    ]) {
      const error = await readFailure(response);
      expect(error.failure).toMatchObject({ code, status });
      expect(error.message).not.toContain('private');
    }
  });
  it.each([
    [null, 403],
    [{ code: 'unknown', message: 'private' }, 403],
    [{ code: 'csrf_failed', message: '' }, 403],
    [{ code: 'csrf_failed', message: 22 }, 403],
    [{ code: 'csrf_failed', message: 'private' }, 401],
    [{ code: 'server_error', message: 'private' }, 403],
    [{ code: 'http_error', message: 'private' }, 401],
    [{ code: 'http_error', message: 'private' }, 500],
  ])('rejects malformed versioned errors %#', async (error, status) => {
    const result = await readFailure(reply({ version: 1, error }, status));
    expect(result.failure).toMatchObject({
      code: 'invalid_response',
      status,
      recovery: 'reload',
    });
    expect(result.message).not.toContain('private');
  });
  it('rejects redirects without following a location', async () => {
    const response = new Response(null, {
      status: 302,
      headers: { Location: '/login' },
    });
    await expect(readResponse(response, 'id')).rejects.toMatchObject({
      failure: { code: 'unexpected_redirect', status: 302 },
    });
    const opaque = new Response();
    Object.defineProperties(opaque, {
      type: { value: 'opaqueredirect' },
      status: { value: 0 },
    });
    await expect(readResponse(opaque, 'id')).rejects.toMatchObject({
      failure: { code: 'unexpected_redirect', status: null },
    });
  });
  it('rejects non-JSON, invalid JSON, and mismatched identities before rendering', async () => {
    for (const response of [
      new Response('private'),
      new Response('{', { headers: { 'Content-Type': 'application/json' } }),
      reply({ version: 1, id: 'other' }, 422),
    ])
      await expect(readResponse(response, 'id')).rejects.toMatchObject({
        failure: { code: 'invalid_response', recovery: 'reload' },
      });
  });
  it('retains a diagnostic cause outside public feedback', () => {
    const cause = new Error('private');
    const error = requestError('network_error', null, cause);
    expect(error.cause).toBe(cause);
    expect(error.failure).toMatchObject({
      code: 'network_error',
      status: null,
      recovery: 'retry',
    });
    expect(JSON.stringify(error.failure)).not.toContain('private');
    expect(requestError('client_error', 200).failure).toMatchObject({
      recovery: 'reload',
      status: 200,
    });
  });
});
