import { record } from '../state/values';
import { mapNonces, parseDocument } from './nonces';

export interface DocumentIdentity {
  version: string;
  application: string;
  session: string;
  policy: string;
  nonceCount?: number;
}

/** Read runtime-owned compatibility data without treating it as an authorization capability. */
export function documentIdentity(
  document: Document,
): DocumentIdentity | undefined {
  const markers = document.head.querySelectorAll(
    'meta[name="interact-navigation"]',
  );
  if (markers.length !== 1) return;
  try {
    const value: unknown = JSON.parse(
      markers[0]?.getAttribute('content') ?? 'null',
    );
    if (
      !record(value) ||
      typeof value.version !== 'string' ||
      typeof value.application !== 'string' ||
      typeof value.session !== 'string' ||
      typeof value.policy !== 'string' ||
      (value.nonceCount !== undefined &&
        (typeof value.nonceCount !== 'number' ||
          !Number.isSafeInteger(value.nonceCount) ||
          value.nonceCount < 0))
    )
      return;
    return {
      version: value.version,
      application: value.application,
      session: value.session,
      policy: value.policy,
      ...(typeof value.nonceCount === 'number'
        ? { nonceCount: value.nonceCount }
        : {}),
    };
  } catch {
    return;
  }
}

/** Parse full HTML without running scripts; incompatible document policies require a native visit. */
export function incomingDocument(
  html: string,
  current: Document,
  nonces: readonly string[] = [],
): Document {
  if (
    !/<!doctype\s+html[\s>]/i.test(html) ||
    !/<html[\s>]/i.test(html) ||
    !/<body[\s>]/i.test(html)
  )
    throw new Error('Navigation requires a complete HTML document.');
  const { document: next, originals } = parseDocument(html, current, nonces);
  const before = documentIdentity(current);
  const after = documentIdentity(next);
  if (
    !before ||
    !after ||
    before.version !== after.version ||
    before.application !== after.application ||
    before.session !== after.session ||
    before.policy !== after.policy ||
    next.querySelector('base, meta[http-equiv]')
  )
    throw new Error('The destination requires a fresh browser document.');
  mapNonces(next, current, nonces, originals);
  return next;
}

/** Replace document attributes while preserving page feedback until navigation settles. */
export function copyAttributes(from: Element, to: Element): void {
  for (const name of to.getAttributeNames()) to.removeAttribute(name);
  for (const attribute of from.attributes)
    to.setAttribute(attribute.name, attribute.value);
}
