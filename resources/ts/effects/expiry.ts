import type { BrowserEffects } from './browser';
import type { RequestFailure } from '../types';

const documents = new WeakMap<Document, { shown: boolean }>();

/**
 * Offer one native reload prompt per page visit after uncanceled expiry feedback.
 *
 * Bookkeeping belongs to the document so independent islands share the allowance.
 */
export function presentExpiry(
  root: HTMLElement,
  failure: RequestFailure,
  effects: BrowserEffects,
): void {
  if (
    !root.isConnected ||
    !['snapshot_expired', 'session_changed', 'csrf_failed'].includes(
      failure.code,
    )
  )
    return;

  const document = root.ownerDocument;
  let state = documents.get(document);
  if (!state) {
    state = { shown: false };
    documents.set(document, state);
    const visit = state;
    document.addEventListener('interact:navigated', () => {
      visit.shown = false;
    });
  }
  if (state.shown) return;
  state.shown = true;
  effects.confirmReload();
}
