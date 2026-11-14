import { record } from '../state/values';

export interface Redirect {
  url: string;
  replace: boolean;
  navigate?: boolean;
}

/** Validate server destinations before applying markup, state, or browser effects. */
export function destination(
  value: unknown,
  sameOrigin = false,
): value is string {
  if (
    typeof value !== 'string' ||
    !value ||
    value
      .split('')
      .some(
        (character) =>
          character.charCodeAt(0) <= 32 ||
          character.charCodeAt(0) === 127 ||
          character === '\\',
      )
  )
    return false;
  if (/^[a-z][a-z0-9+.-]*:/i.test(value) && !/^https?:\/\//i.test(value))
    return false;
  try {
    const url = new URL(value, window.location.href);
    return (
      ['http:', 'https:'].includes(url.protocol) &&
      !url.username &&
      !url.password &&
      (!sameOrigin || url.origin === window.location.origin)
    );
  } catch {
    return false;
  }
}

export function redirect(value: unknown): value is Redirect {
  return (
    record(value) &&
    (Object.keys(value).length === 2 ||
      (Object.keys(value).length === 3 &&
        typeof value.navigate === 'boolean')) &&
    destination(value.url) &&
    typeof value.replace === 'boolean'
  );
}

/**
 * Executes accepted browser outcomes without buffering files or replacing normal routing.
 * Native downloads remain independently authorized requests with browser-owned completion.
 */
export class BrowserEffects {
  /**
   * Offer recovery without discarding drafts until the user accepts a native reload.
   */
  confirmReload(): void {
    if (
      window.confirm(
        'This page has expired.\nReload this page? Unsaved changes will be lost.\nChoose Cancel to keep this page open and copy your work.',
      )
    )
      this.reload();
  }

  /**
   * Reload the current document without replaying an island action.
   */
  reload(): void {
    window.location.reload();
  }

  navigate(target: Redirect): void {
    const current = new URL(window.location.href);
    const next = new URL(target.url, current);
    const fragmentNavigation =
      next.origin === current.origin &&
      next.pathname === current.pathname &&
      next.search === current.search &&
      (next.hash !== '' || current.hash !== '');
    window.location[target.replace ? 'replace' : 'assign'](target.url);
    // A fragment-only visit would keep a terminal client alive with its previous snapshot.
    if (fragmentNavigation) window.location.reload();
  }

  download(url: string): void {
    const link = document.createElement('a');
    link.href = url;
    link.download = '';
    document.body.append(link);
    try {
      link.click();
    } finally {
      link.remove();
    }
  }
}
