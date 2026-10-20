import type { Alpine } from 'alpinejs';

declare global {
  /**
   * Describe the Alpine instance exposed by the standalone browser entry point.
   */
  interface Window {
    Alpine?: Alpine;
  }
}
