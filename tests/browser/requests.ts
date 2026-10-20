import type { Locator, Request } from '@playwright/test';

function record(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

/**
 * Identify an island's transport at the test boundary without counting unrelated page work.
 */
export function targetsIsland(request: Request, id: string): boolean {
  if (!request.url().endsWith('/_interact/update')) return false;
  const body: unknown = request.postDataJSON();
  if (!record(body) || typeof body.snapshot !== 'string') return false;
  const encoded = body.snapshot.split('.')[0];
  if (!encoded) return false;
  const envelope: unknown = JSON.parse(
    Buffer.from(encoded, 'base64url').toString('utf8'),
  );
  return record(envelope) && record(envelope.data) && envelope.data.id === id;
}

/**
 * Read the stable identity of the island that owns a labelled example region.
 */
export async function islandId(region: Locator): Promise<string> {
  return region.evaluate((element) => {
    const root = element.closest<HTMLElement>('[data-interact-id]');
    const id = root?.dataset.interactId;
    if (!id) throw new Error('Missing owning island.');
    return id;
  });
}
