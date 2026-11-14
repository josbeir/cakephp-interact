import { afterEach, beforeEach, expect, it, vi } from 'vite-plus/test';
import { BrowserEffects } from '../../../resources/ts/effects/browser';
import { presentExpiry } from '../../../resources/ts/effects/expiry';
import { requestError } from '../../../resources/ts/transport/failure';
import type { FailureCode } from '../../../resources/ts/types';

beforeEach(() => {
  document.dispatchEvent(new Event('interact:navigated'));
  vi.spyOn(window, 'confirm').mockReturnValue(false);
});

afterEach(() => {
  document.body.replaceChildren();
  vi.unstubAllGlobals();
});

function present(
  code: FailureCode = 'snapshot_expired',
  document_ = document,
): void {
  const root = document_.createElement('section');
  document_.body.append(root);
  presentExpiry(root, requestError(code, 409).failure, new BrowserEffects());
}

it('shows one fixed warning across islands and resets only after an enhanced visit completes', () => {
  present();
  expect(window.confirm).toHaveBeenCalledWith(
    'This page has expired.\nReload this page? Unsaved changes will be lost.\nChoose Cancel to keep this page open and copy your work.',
  );
  present('csrf_failed');
  document.dispatchEvent(new Event('interact:navigate', { cancelable: true }));
  document.dispatchEvent(new Event('interact:url'));
  window.dispatchEvent(new HashChangeEvent('hashchange'));
  window.dispatchEvent(new PopStateEvent('popstate'));
  present('session_changed');
  expect(window.confirm).toHaveBeenCalledOnce();
  document.dispatchEvent(new Event('interact:navigated'));
  present();
  expect(window.confirm).toHaveBeenCalledTimes(2);
  document.dispatchEvent(new Event('interact:navigated'));
  present();
  expect(window.confirm).toHaveBeenCalledTimes(3);
});

it.each<FailureCode>([
  'invalid_snapshot',
  'unauthenticated',
  'forbidden',
  'invalid_request',
  'upload_too_large',
  'server_error',
  'http_error',
  'network_error',
  'unexpected_redirect',
  'invalid_response',
  'cancelled',
  'client_error',
])('retains existing presentation for %s', (code) => {
  present(code);
  expect(window.confirm).not.toHaveBeenCalled();
  present();
  expect(window.confirm).toHaveBeenCalledOnce();
});

it('keeps prompt allowances separate for different owner documents', () => {
  present();
  present('session_changed', document.implementation.createHTMLDocument());
  expect(window.confirm).toHaveBeenCalledTimes(2);
});

it('ignores detached roots without consuming the prompt allowance', () => {
  presentExpiry(
    document.createElement('section'),
    requestError('csrf_failed', 403).failure,
    new BrowserEffects(),
  );
  expect(window.confirm).not.toHaveBeenCalled();
  present();
  expect(window.confirm).toHaveBeenCalledOnce();
});

it('accepts a native reload without issuing an island request', () => {
  const confirm = vi.fn().mockReturnValue(true);
  const reload = vi.fn();
  vi.stubGlobal('window', { confirm, location: { reload } });
  new BrowserEffects().confirmReload();
  expect(confirm).toHaveBeenCalledOnce();
  expect(reload).toHaveBeenCalledOnce();
});
