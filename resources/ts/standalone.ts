import Alpine from '@alpinejs/csp';
import install from './index';

/**
 * Start the bundled Alpine CSP runtime for Composer-only installations.
 *
 * Application-owned Alpine must use the plugin entry point with autoScript disabled.
 *
 * @throws Error If another Alpine instance is already attached to the window.
 */
export function start(): void {
  if (window.Alpine)
    throw new Error(
      'Alpine is already loaded. Disable Interact autoScript and use the npm integration.',
    );
  install(Alpine);
  window.Alpine = Alpine;
  Alpine.start();
}
start();
